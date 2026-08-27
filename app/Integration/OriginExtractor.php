<?php

declare(strict_types=1);

namespace App\Integration;

/**
 * Extrai e mapeia lançamentos da origem para o contrato do Gestão.
 *
 * Só leitura. Todo o conhecimento sobre as armadilhas do schema real está aqui,
 * documentado, porque cada uma delas corromperia números silenciosamente.
 */
final class OriginExtractor
{
    /**
     * Situações que significam REALIZADO (pago/recebido).
     *
     * Confirmado na tabela `situacao` de ambos os bancos e no cruzamento com
     * `datapgto`. Todas as demais situações — inclusive `aguard`, `processo`,
     * `anuência`, `Negativados`, `Extintos`, `Permuta` — significam que o
     * dinheiro AINDA NÃO entrou/saiu, então o título fica em aberto.
     */
    private const REALIZED = ['pago'];

    private const CANCELLED = ['canc'];

    public function __construct(
        private readonly OriginReader $reader,
        private readonly string $sourceCode,
        private readonly string $type,
    ) {}

    /**
     * Contas da origem: nome → id local. Os ids NÃO são estáveis entre os dois
     * bancos (o mesmo 'Marco' é 1 em contas e 16 em contasareceber), então a
     * identidade de conta usada pelo Gestão é sempre resolvida pelo NOME.
     *
     * @return array<string, string>
     */
    public function accounts(): array
    {
        $map = [];
        foreach ($this->reader->select('SELECT id, nome FROM contas ORDER BY id') as $row) {
            $map[(string) $row['nome']] = (string) $row['id'];
        }

        return $map;
    }

    /**
     * Traz o que VENCE no período OU o que foi PAGO no período.
     *
     * Só o vencimento não basta: um título que venceu em 2025 e foi pago em
     * janeiro de 2026 é movimento de caixa de 2026 e precisa aparecer. Isso não
     * é hipótese — a conciliação da Acop Files de janeiro/2026 registra três
     * depósitos assim (docs 12273, 12370 e 12427, vencimentos de out/2025 a
     * dez/2025), e nenhum deles tinha sido importado.
     *
     * A identidade continua sendo `source_system` + `external_id`, então trazer
     * o mesmo título por dois critérios não duplica nada.
     *
     * @return list<array<string, mixed>> linhas cruas já normalizadas
     */
    public function fetch(string $from, string $to): array
    {
        // CAST no servidor, sempre.
        //
        // Em `contas` as colunas monetárias são `double(20,2)` — ponto flutuante.
        // Lidas direto, `670000.00` chega como `670000`, e o contrato da API do
        // Gestão exige exatamente duas casas. O CAST para DECIMAL e depois CHAR
        // congela a representação decimal no próprio servidor, antes de qualquer
        // conversão do PHP.
        return $this->reader->select(
            'SELECT id,
                    ndocumento,
                    tipo,
                    nomefantasia,
                    cnpj,
                    vencimento,
                    dataemissao,
                    datapgto,
                    obs,
                    categoria,
                    centrocusto,
                    conta,
                    situacao,
                    parcela,
                    nparcela,
                    CAST(CAST(COALESCE(valor,0)      AS DECIMAL(20,2)) AS CHAR) AS valor,
                    CAST(CAST(COALESCE(acrescimo,0)  AS DECIMAL(20,2)) AS CHAR) AS acrescimo,
                    CAST(CAST(COALESCE(desconto,0)   AS DECIMAL(20,2)) AS CHAR) AS desconto,
                    CAST(CAST(COALESCE(valortotal,0) AS DECIMAL(20,2)) AS CHAR) AS valortotal
             FROM lancamentos
             WHERE vencimento BETWEEN ? AND ?
                OR (datapgto IS NOT NULL AND datapgto BETWEEN ? AND ?)
             ORDER BY id',
            [$from, $to, $from, $to],
        );
    }

    /**
     * Mapeia uma linha da origem para o payload do Gestão.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $accountIds  nome da conta → id canônico do Gestão
     * @return array{ok: bool, reason?: string, payload?: array<string, mixed>, settlement?: array<string, string>|null, meta: array<string, mixed>}
     */
    public function map(array $row, array $accountIds): array
    {
        $id = (string) $row['id'];
        $situacao = $row['situacao'] === null ? null : trim((string) $row['situacao']);

        $meta = [
            'external_id' => $id,
            'situacao' => $situacao,
            'valortotal' => (string) $row['valortotal'],
            'conta' => $row['conta'],
        ];

        // --- rejeições explícitas -----------------------------------------
        if ($situacao === null) {
            return ['ok' => false, 'reason' => 'SITUACAO_NULA', 'meta' => $meta];
        }
        if ($row['vencimento'] === null || str_starts_with((string) $row['vencimento'], '0000')) {
            return ['ok' => false, 'reason' => 'VENCIMENTO_AUSENTE', 'meta' => $meta];
        }
        if (in_array($situacao, self::CANCELLED, true)) {
            // Cancelado na origem. O Gestão tem cancelamento próprio, com motivo
            // obrigatório e trilha — importar como título vivo seria mentir sobre
            // o estado, e inventar um motivo de cancelamento seria pior.
            return ['ok' => false, 'reason' => 'CANCELADO_NA_ORIGEM', 'meta' => $meta];
        }

        $totalCents = $this->cents((string) $row['valortotal']);
        if ($totalCents <= 0) {
            return ['ok' => false, 'reason' => 'VALOR_ZERO_OU_NEGATIVO', 'meta' => $meta];
        }

        // --- data de emissão ----------------------------------------------
        // Há registros com ano absurdo (ex.: 3012) digitados à mão. Emissão
        // posterior ao vencimento é recusada pelo contrato da API, então a
        // inconsistência é reportada em vez de silenciosamente "corrigida".
        $emissao = (string) ($row['dataemissao'] ?? '');
        $vencimento = (string) $row['vencimento'];
        if ($emissao === '' || str_starts_with($emissao, '0000') || $emissao > $vencimento) {
            $emissao = $vencimento;
            $meta['emissao_ajustada'] = true;
        }

        // --- dinheiro -------------------------------------------------------
        // `valortotal` é a verdade monetária da origem: é o campo que o sistema
        // soma e exibe, e ele NÃO é derivável de valor+acréscimo−desconto —
        // 944 registros divergem, porque os usuários ajustam o total à mão.
        // Enviar a decomposição faria o Gestão recalcular um total diferente do
        // que o financeiro enxerga hoje. A decomposição original é preservada em
        // `notes` para rastreabilidade.
        $payload = [
            'external_id' => $id,
            'document_number' => $this->nullableString($row['ndocumento'], 120),
            'issue_date' => $emissao,
            'due_date' => $vencimento,
            'original_amount' => $this->money($totalCents),
            'discount_amount' => '0.00',
            'addition_amount' => '0.00',
            'currency' => 'BRL',
            // Uma linha da origem JÁ É uma parcela: `parcela`/`nparcela` dizem
            // "esta é a 2 de 3", e cada parcela é uma linha com seu próprio valor
            // e vencimento. Usar `nparcela` como installment_count multiplicaria
            // o dinheiro pelo número de parcelas.
            'installment_count' => 1,
        ];

        if ($row['nomefantasia'] !== null && trim((string) $row['nomefantasia']) !== '') {
            $payload['party'] = [
                'type' => $this->type === 'PAYABLE' ? 'SUPPLIER' : 'CUSTOMER',
                'name' => mb_substr(trim((string) $row['nomefantasia']), 0, 191),
            ];
        }

        $conta = $row['conta'] === null ? '' : trim((string) $row['conta']);
        if ($conta !== '' && isset($accountIds[$conta])) {
            $payload['account_id'] = (int) $accountIds[$conta];
        } else {
            $meta['conta_nao_mapeada'] = $conta;
        }

        // Categoria e centro de custo vem da origem como TEXTO, e agora existem
        // como cadastro no Gestao (sincronizados das mesmas origens). Guardar so
        // dentro de `notes` obrigava a tela a garimpar texto para exibir o que ja
        // e um dado estruturado.
        $payload['categoria_nome'] = $this->nullableString($row['categoria'] ?? null, 191);
        $payload['centro_custo_nome'] = $this->nullableString($row['centrocusto'] ?? null, 191);

        $notes = $this->notes($row);
        if ($notes !== '') {
            $payload['notes'] = $notes;
        }

        // --- realização ------------------------------------------------------
        $settlement = null;
        if (in_array($situacao, self::REALIZED, true)) {
            $datapgto = (string) ($row['datapgto'] ?? '');
            if ($datapgto === '' || str_starts_with($datapgto, '0000')) {
                // A origem afirma 'pago' mas não tem data. Inventar uma data
                // seria criar um fato financeiro que não existe, então o título
                // entra e a realização fica pendente, contabilizada e reportada.
                $meta['pago_sem_data'] = true;
            } else {
                $settlement = ['settlement_date' => $datapgto, 'amount' => $this->money($totalCents)];
            }
        }

        return ['ok' => true, 'payload' => $payload, 'settlement' => $settlement, 'meta' => $meta];
    }

    /** @param array<string, mixed> $row */
    private function notes(array $row): string
    {
        $parts = [];
        if ($row['obs'] !== null && trim((string) $row['obs']) !== '') {
            $parts[] = trim((string) $row['obs']);
        }
        foreach (['tipo' => 'Tipo', 'categoria' => 'Categoria', 'centrocusto' => 'Centro de custo'] as $field => $label) {
            if ($row[$field] !== null && trim((string) $row[$field]) !== '') {
                $parts[] = $label.': '.trim((string) $row[$field]);
            }
        }
        if ($row['parcela'] !== null && $row['nparcela'] !== null) {
            $parts[] = 'Parcela '.$row['parcela'].'/'.$row['nparcela'];
        }
        // Rastreabilidade da decisão monetária: guarda a decomposição original
        // quando ela não bate com o total informado pela origem.
        $decomposto = $this->cents((string) $row['valor'])
            + $this->cents((string) $row['acrescimo'])
            - $this->cents((string) $row['desconto']);
        if ($decomposto !== $this->cents((string) $row['valortotal'])) {
            $parts[] = sprintf(
                'Origem: valor %s + acréscimo %s − desconto %s = %s, mas valortotal informado = %s (total da origem preservado)',
                $row['valor'], $row['acrescimo'], $row['desconto'],
                $this->money($decomposto), $row['valortotal'],
            );
        }

        return mb_substr(implode(' | ', $parts), 0, 10000);
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $max);
    }

    public function cents(string $amount): int
    {
        $normalized = trim($amount);
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $cents = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    public function money(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);

        return ($negative ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function sourceCode(): string
    {
        return $this->sourceCode;
    }

    public function type(): string
    {
        return $this->type;
    }
}
