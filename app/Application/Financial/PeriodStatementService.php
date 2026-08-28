<?php

namespace App\Application\Financial;

use App\Contracts\AuditEventRecorder;
use App\Domain\Financial\CompanyGroup;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\ManualMovementDirection;
use App\Domain\Financial\Enums\PeriodStatementSection;
use App\Domain\Financial\Enums\PeriodStatementStatus;
use App\Domain\Financial\Money;
use App\Domain\Financial\PeriodStatementRefreshResult;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\PeriodStatement;
use App\Models\PeriodStatementExclusion;
use App\Models\PeriodStatementLine;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conciliação (Movimento do Período) de uma conta: entradas, saídas e saldo
 * corrido, com ciclo de vida.
 *
 * Duas fontes entram na mesma linha do tempo:
 *
 *  1. as LIQUIDAÇÕES confirmadas dos títulos — o dinheiro que de fato mudou de
 *     mãos, vindo de Contas a Pagar e Contas a Receber. Não é o título: um
 *     título de dezembro pago em janeiro é movimento de janeiro; um título que
 *     vence em janeiro e ninguém pagou não aparece. O recorte é pela data da
 *     liquidação e não pelo vencimento, porque o relatório conta o caixa e não
 *     a competência;
 *  2. os MOVIMENTOS MANUAIS — PIX avulso, tarifa, rendimento, transferência,
 *     ajuste. Dinheiro que se moveu sem passar por nenhuma das duas origens.
 *
 * Criada ABERTA, a conciliação evolui com `refresh()` ("Atualizar") enquanto o
 * mês acontece: pega o que está elegível agora, compara com o que a
 * conciliação já tinha pela IDENTIDADE real do movimento (a liquidação ou o
 * movimento manual — nunca nome/valor/histórico, que dois lançamentos
 * legítimos podem repetir) e reflete o resultado. `close()` congela: depois
 * disso a conciliação é o retrato definitivo do período, e `refresh()` passa a
 * recusar.
 *
 * Nada aqui altera título, liquidação, movimento manual ou status nenhum — é
 * sempre leitura da base mais a gravação do resumo.
 */
class PeriodStatementService
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    /**
     * Linhas do período, já com saldo corrido — sem gravar nada. Usada pela
     * prévia da criação: o que a pessoa vê antes de confirmar é montado pelo
     * mesmo caminho que `create()` usa para gravar.
     *
     * `pending` vem separado de `lines` porque é outra coisa: são títulos que
     * ainda não caíram no banco. Não somam saldo nem total nenhum.
     *
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     pending: list<array<string, mixed>>,
     *     opening_cents: int,
     *     closing_cents: int,
     *     total_in_cents: int,
     *     total_out_cents: int
     * }
     */
    public function preview(
        int $accountId,
        string $from,
        string $to,
        int $openingCents = 0,
        ?int $bankAccountId = null,
    ): array {
        $elegiveis = $this->ordenar(array_values($this->elegiveis($accountId, $from, $to, $bankAccountId)));
        [$linhas, $totais] = $this->montarLinhas($elegiveis, $openingCents);

        return [
            'lines' => $linhas,
            'pending' => $this->linhasPendentes($accountId, $to, $bankAccountId, count($linhas)),
            'opening_cents' => $openingCents,
            'closing_cents' => $totais['closing_cents'],
            'total_in_cents' => $totais['total_in_cents'],
            'total_out_cents' => $totais['total_out_cents'],
        ];
    }

    /**
     * A conta escolhida é a que herda o que não tem banco definido?
     *
     * As origens (`contas`/`contasareceber`) não guardam banco — não existe a
     * coluna, foi conferido no `information_schema` das duas. O que resolve
     * isso não é uma convenção, é o cadastro: **cada empresa opera por uma
     * única conta bancária** (confirmado com a administração em 26/08/2026,
     * ADR-018). Se a empresa tem uma conta só, o que veio sem banco passou por
     * ela, porque não existe outra por onde passar.
     *
     * Devolve `false` quando a empresa tem duas ou mais contas ativas. Aí a
     * premissa não vale e nada sem banco entra em conciliação nenhuma — ficam
     * contadas em `contarSemContaBancaria()` para a operadora atribuir. É esse
     * ramo que impede a volta da convenção do `is_default`, que custou
     * −R$ 1.805.279,37 em 2026 (ADR-017).
     *
     * Sem banco escolhido (`null`), o recorte é só por empresa: é como as
     * conciliações gravadas antes desta mudança funcionavam, e elas continuam
     * abrindo do mesmo jeito.
     */
    private function bancoHerdaSemConta(int $accountId, ?int $bankAccountId): bool
    {
        if ($bankAccountId === null) {
            return true;
        }

        return BankAccount::contaUnicaDaEmpresa($accountId)?->id === $bankAccountId;
    }

    /**
     * Cria a conciliação já ABERTA, com as linhas de tudo que já está
     * elegível na conta e no período agora.
     *
     * O saldo inicial é o que a pessoa informou — este método não decide um
     * valor sozinho; quem exige o campo preenchido é a validação do
     * controller, de propósito, porque "obrigatório" é regra de formulário,
     * não de domínio (`0` continua sendo um saldo inicial válido quando é
     * isso mesmo que a pessoa digitou).
     *
     * Recusa abrir uma segunda conciliação para a mesma conta com período que
     * se sobrepõe a uma já ABERTA: um movimento datado nesse meio não saberia
     * a qual das duas pertence.
     */
    public function create(
        int $accountId,
        string $from,
        string $to,
        int $openingCents,
        ?int $actorId = null,
        ?int $bankAccountId = null,
    ): PeriodStatement {
        // A sobreposição é por conta bancária, não só por empresa: a mesma
        // empresa pode ter dois bancos conciliados no mesmo mês, e são dois
        // extratos independentes. Duas conciliações do MESMO banco no mesmo
        // período continuam proibidas — um movimento datado no meio não saberia
        // a qual das duas pertence.
        $sobreposta = PeriodStatement::query()
            ->where('account_id', $accountId)
            ->where('status', PeriodStatementStatus::Open->value)
            ->where('period_start', '<=', $to)
            ->where('period_end', '>=', $from)
            ->when(
                $bankAccountId !== null,
                fn ($q) => $q->where('bank_account_id', $bankAccountId),
                fn ($q) => $q->whereNull('bank_account_id'),
            )
            ->first();

        if ($sobreposta !== null) {
            throw new DomainException(sprintf(
                'Já existe uma conciliação em andamento para esta conta cobrindo %s a %s. '
                .'Atualize aquela em vez de abrir outra para o mesmo período.',
                $sobreposta->period_start->format('d/m/Y'),
                $sobreposta->period_end->format('d/m/Y'),
            ));
        }

        $conta = Conta::query()->find($accountId);
        $banco = $bankAccountId === null ? null : BankAccount::query()->find($bankAccountId);
        $previa = $this->preview($accountId, $from, $to, $openingCents, $bankAccountId);

        return DB::transaction(function () use ($accountId, $bankAccountId, $banco, $conta, $from, $to, $openingCents, $previa, $actorId): PeriodStatement {
            $agora = now();

            $statement = PeriodStatement::create([
                'account_id' => $accountId,
                'account_name' => CompanyGroup::displayName($accountId) ?? $conta?->nome ?? ('Conta '.$accountId),
                // Congelado como texto igual ao cabeçalho da planilha
                // ("Banco Itaú - Agência 6260 - C/C 13377-9"). O id fica ao lado
                // para a conciliação continuar sabendo qual conta é mesmo depois
                // de o cadastro ser renomeado.
                'account_bank' => $banco?->fullLabel() ?? $conta?->banco,
                'bank_account_id' => $bankAccountId,
                'status' => PeriodStatementStatus::Open->value,
                'period_start' => $from,
                'period_end' => $to,
                'opening_balance_cents' => $openingCents,
                'closing_balance_cents' => $previa['closing_cents'],
                'total_in_cents' => $previa['total_in_cents'],
                'total_out_cents' => $previa['total_out_cents'],
                // Conta só o movimento. As linhas pendentes não são movimento e
                // entrariam aqui como um número inflado que não bate com o
                // extrato nem com os totais.
                'line_count' => count($previa['lines']),
                'generated_by' => $actorId,
                'generated_at' => $agora,
                'last_synced_at' => $agora,
                'correlation_id' => (string) Str::uuid(),
            ]);

            $this->inserirLinhas($statement, [...$previa['lines'], ...$previa['pending']]);

            $this->audit->record(
                'PERIOD_STATEMENT_CREATED',
                PeriodStatement::class,
                $statement->id,
                null,
                [
                    'account_id' => $accountId,
                    'period_start' => $from,
                    'period_end' => $to,
                    'opening_balance_cents' => $openingCents,
                    'line_count' => count($previa['lines']),
                ],
                null,
                $actorId,
                $statement->correlation_id,
            );

            return $statement;
        });
    }

    /**
     * "Atualizar conciliação": busca de novo o que está elegível na conta e
     * no período, e reflete no relatório sem duplicar nada.
     *
     * A identidade de uma linha é o que a originou — `title_settlement_id` ou
     * `manual_movement_id` — nunca nome, valor ou histórico, porque dois
     * lançamentos iguais podem ser legítimos (duas tarifas de R$ 35 em dias
     * diferentes, por exemplo). Comparando por identidade:
     *
     *  - identidade nova → linha nova;
     *  - identidade já existia mas os dados mudaram (movimento manual
     *    corrigido enquanto a conciliação está ABERTA) → linha atualizada;
     *  - identidade que a conciliação tinha e não está mais elegível
     *    (movimento manual excluído) → linha sai, sem deixar valor fantasma.
     *
     * Quando nada muda, ainda registra que a atualização rodou (`last_synced_at`)
     * — é o mesmo padrão que a sincronização com as origens já usa: rodar sem
     * achar novidade também é informação. Só a auditoria (`audit_events`) fica
     * de fora quando não há nenhuma mudança real, para não virar ruído.
     */
    public function refresh(PeriodStatement $statement, ?int $actorId = null): PeriodStatementRefreshResult
    {
        return DB::transaction(function () use ($statement, $actorId): PeriodStatementRefreshResult {
            $statement = PeriodStatement::query()->lockForUpdate()->findOrFail($statement->getKey());

            if (! $statement->isOpen()) {
                throw new DomainException('Esta conciliação está fechada e não pode ser atualizada.');
            }

            $atuais = $this->elegiveis(
                $statement->account_id,
                $statement->period_start->toDateString(),
                $statement->period_end->toDateString(),
                $statement->bank_account_id,
                $statement,
            );

            // Só o MOVIMENTO entra no diff. O bloco de pendências é um retrato
            // do que está em aberto agora e muda a cada baixa registrada; se
            // entrasse aqui, toda atualização acusaria mudança e o "nada novo"
            // — que é informação — nunca mais apareceria.
            $existentes = $statement->lines()
                ->where('section', PeriodStatementSection::Ledger->value)
                ->get()
                ->keyBy(fn (PeriodStatementLine $l): string => $this->chaveDaLinha($l));

            $novos = 0;
            $atualizados = 0;
            $chavesAtuais = [];

            foreach ($atuais as $chave => $dados) {
                $chavesAtuais[$chave] = true;
                $linha = $existentes->get($chave);

                if ($linha === null) {
                    $novos++;

                    continue;
                }

                if ($this->linhaMudou($linha, $dados)) {
                    $atualizados++;
                }
            }

            $removidos = $existentes->keys()->reject(fn (string $chave): bool => isset($chavesAtuais[$chave]))->count();

            $antes = [
                'closing_balance_cents' => $statement->closing_balance_cents,
                'total_in_cents' => $statement->total_in_cents,
                'total_out_cents' => $statement->total_out_cents,
                'line_count' => $statement->line_count,
            ];

            if ($novos > 0 || $atualizados > 0 || $removidos > 0) {
                $this->reconstruir($statement, $atuais);

                $this->audit->record(
                    'PERIOD_STATEMENT_REFRESHED',
                    PeriodStatement::class,
                    $statement->id,
                    $antes,
                    [
                        'closing_balance_cents' => $statement->closing_balance_cents,
                        'total_in_cents' => $statement->total_in_cents,
                        'total_out_cents' => $statement->total_out_cents,
                        'line_count' => $statement->line_count,
                        'novos' => $novos,
                        'atualizados' => $atualizados,
                        'removidos' => $removidos,
                    ],
                    null,
                    $actorId,
                    (string) Str::uuid(),
                );
            } else {
                // Nenhum movimento novo, mas o que está em aberto pode ter
                // mudado — uma baixa registrada hoje tira o título do rodapé
                // sem criar linha nenhuma no período. Reconstrói só esse bloco.
                $this->regravarPendentes($statement);
                $statement->update(['last_synced_at' => now()]);
            }

            return new PeriodStatementRefreshResult(
                statement: $statement->fresh('lines'),
                novos: $novos,
                atualizados: $atualizados,
                removidos: $removidos,
            );
        });
    }

    /**
     * Reconstrói o MOVIMENTO da conciliação a partir do que está elegível
     * agora: ordena (respeitando posição manual, se houver), calcula saldo
     * corrido, regrava pendências e persiste. Único caminho que apaga e
     * reinsere `period_statement_lines` — usado por `refresh()` (quando o
     * diff acusa mudança) e por `reordenarDia()` (sempre), para as duas
     * situações compartilharem a mesma aritmética de saldo em vez de um
     * segundo caminho menos testado.
     *
     * @param  array<string, array<string, mixed>>  $atuais  saída de `elegiveis()`
     * @return array{0: list<array<string, mixed>>, 1: array{closing_cents: int, total_in_cents: int, total_out_cents: int}}
     */
    private function reconstruir(PeriodStatement $statement, array $atuais): array
    {
        $posicoesManuais = $this->posicoesManuaisAtuais($statement);

        $ordenados = $this->ordenar(array_values($atuais), $posicoesManuais);
        [$linhas, $totais] = $this->montarLinhas($ordenados, $statement->opening_balance_cents, $posicoesManuais);
        $pendentes = $this->linhasPendentes(
            $statement->account_id,
            $statement->period_end->toDateString(),
            $statement->bank_account_id,
            count($linhas),
        );

        $statement->lines()->delete();
        $this->inserirLinhas($statement, [...$linhas, ...$pendentes]);

        $statement->update([
            'closing_balance_cents' => $totais['closing_cents'],
            'total_in_cents' => $totais['total_in_cents'],
            'total_out_cents' => $totais['total_out_cents'],
            'line_count' => count($linhas),
            'last_synced_at' => now(),
        ]);

        return [$linhas, $totais];
    }

    /**
     * As posições manuais em vigor, lidas das linhas ATUAIS antes de
     * `reconstruir()` apagá-las — é o que permite uma reordenação sobreviver
     * ao próximo `refresh()` (inclusive o automático a cada 5 minutos): a
     * linha nova recebe a mesma posição da antiga porque as duas têm a
     * mesma identidade (`chaveDaLinha()`), não o mesmo id de banco de dados.
     *
     * @return array<string, int>
     */
    private function posicoesManuaisAtuais(PeriodStatement $statement): array
    {
        return $statement->lines()
            ->where('section', PeriodStatementSection::Ledger->value)
            ->whereNotNull('manual_position')
            ->get()
            ->mapWithKeys(fn (PeriodStatementLine $l): array => [$this->chaveDaLinha($l) => (int) $l->manual_position])
            ->all();
    }

    /**
     * Reordena à mão as linhas de UM dia: o extrato do banco às vezes lista
     * duas movimentações do mesmo dia numa ordem que o critério padrão
     * (data + quando foi registrada no Gestão) não reproduz, e a planilha
     * precisa bater com o extrato exatamente. A posição escolhida fica
     * gravada por linha e sobrevive a um `refresh()` posterior — ver
     * `posicoesManuaisAtuais()`.
     *
     * `$orderedLineIds` precisa ser EXATAMENTE o conjunto de linhas de
     * MOVIMENTO daquele dia, sem faltar nem sobrar — meio caminho deixaria a
     * outra metade numa posição indefinida.
     *
     * Só mexe no saldo corrido daquele dia: como o total de entradas e
     * saídas do dia não muda com a ordem interna, o saldo ao FINAL do dia —
     * e portanto de todo dia seguinte — continua exatamente igual. Por isso
     * reordenar não precisa (nem faz sentido) ser um recorte "só aquele
     * dia": `reconstruir()` já resolve isso sozinho, recalculando tudo com o
     * mesmo custo de um `refresh()` normal.
     */
    public function reordenarDia(
        PeriodStatement $statement,
        string $date,
        array $orderedLineIds,
        ?int $actorId = null,
    ): PeriodStatement {
        return DB::transaction(function () use ($statement, $date, $orderedLineIds, $actorId): PeriodStatement {
            $statement = PeriodStatement::query()->lockForUpdate()->findOrFail($statement->getKey());

            if (! $statement->isOpen()) {
                throw new DomainException('Esta conciliação está fechada e não pode ser reordenada.');
            }

            $linhasDoDia = $statement->lines()
                ->where('section', PeriodStatementSection::Ledger->value)
                ->whereDate('movement_date', $date)
                ->get()
                ->keyBy('id');

            $idsAtuais = $linhasDoDia->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $idsRecebidos = collect($orderedLineIds)->map(fn ($id): int => (int) $id)->sort()->values()->all();

            if ($idsAtuais !== $idsRecebidos) {
                throw new DomainException(
                    'A ordem enviada não corresponde às movimentações deste dia. Atualize a página e tente de novo.'
                );
            }

            $antes = [
                'closing_balance_cents' => $statement->closing_balance_cents,
                'total_in_cents' => $statement->total_in_cents,
                'total_out_cents' => $statement->total_out_cents,
            ];

            $posicao = 1;
            foreach ($orderedLineIds as $id) {
                $linhasDoDia->get((int) $id)->update(['manual_position' => $posicao++]);
            }

            $atuais = $this->elegiveis(
                $statement->account_id,
                $statement->period_start->toDateString(),
                $statement->period_end->toDateString(),
                $statement->bank_account_id,
                $statement,
            );

            $this->reconstruir($statement, $atuais);

            $this->audit->record(
                'PERIOD_STATEMENT_LINES_REORDERED',
                PeriodStatement::class,
                $statement->id,
                $antes,
                [
                    'movement_date' => $date,
                    'line_count' => count($orderedLineIds),
                    'closing_balance_cents' => $statement->closing_balance_cents,
                ],
                null,
                $actorId,
                (string) Str::uuid(),
            );

            return $statement->fresh('lines');
        });
    }

    /**
     * Fecha a conciliação: atualiza uma última vez e trava. Depois disso
     * `refresh()` recusa, e o relatório vira o retrato definitivo do período.
     *
     * Não cria um sistema de fechamento novo — o snapshot já era exatamente
     * isto, as linhas gravadas em `period_statement_lines`; fechar só impede
     * que elas voltem a mudar.
     */
    public function close(PeriodStatement $statement, ?int $actorId = null): PeriodStatement
    {
        if (! $statement->isOpen()) {
            throw new DomainException('Esta conciliação já está fechada.');
        }

        return DB::transaction(function () use ($statement, $actorId): PeriodStatement {
            $statement = PeriodStatement::query()->lockForUpdate()->findOrFail($statement->getKey());

            if (! $statement->isOpen()) {
                throw new DomainException('Esta conciliação já está fechada.');
            }

            $this->refresh($statement, $actorId);
            $statement->refresh();

            $statement->update([
                'status' => PeriodStatementStatus::Closed->value,
                'closed_by' => $actorId,
                'closed_at' => now(),
            ]);

            $this->audit->record(
                'PERIOD_STATEMENT_CLOSED',
                PeriodStatement::class,
                $statement->id,
                ['status' => PeriodStatementStatus::Open->value],
                [
                    'status' => PeriodStatementStatus::Closed->value,
                    'closing_balance_cents' => $statement->closing_balance_cents,
                    'line_count' => $statement->line_count,
                ],
                null,
                $actorId,
                (string) Str::uuid(),
            );

            return $statement->fresh();
        });
    }

    /**
     * Exclui SOMENTE o relatório — o resumo. As linhas gravadas somem junto
     * (cascade da FK), mas o que elas resumiam continua intacto: títulos,
     * liquidações e movimentos manuais não são tocados, porque o relatório
     * nunca foi a fonte deles, só uma leitura gravada.
     *
     * Vale para ABERTA e para FECHADA — não há hoje uma regra adicional de
     * segurança para reabrir/excluir uma fechada, então nenhuma foi inventada
     * aqui.
     */
    public function delete(PeriodStatement $statement, ?int $actorId = null): void
    {
        DB::transaction(function () use ($statement, $actorId): void {
            $antes = [
                'account_id' => $statement->account_id,
                'account_name' => $statement->account_name,
                'status' => $statement->status->value,
                'period_start' => $statement->period_start->toDateString(),
                'period_end' => $statement->period_end->toDateString(),
                'opening_balance_cents' => $statement->opening_balance_cents,
                'closing_balance_cents' => $statement->closing_balance_cents,
                'total_in_cents' => $statement->total_in_cents,
                'total_out_cents' => $statement->total_out_cents,
                'line_count' => $statement->line_count,
                'generated_at' => $statement->generated_at->toDateTimeString(),
            ];
            $correlationId = (string) Str::uuid();
            $id = $statement->id;

            $statement->delete();

            $this->audit->record(
                'PERIOD_STATEMENT_DELETED',
                PeriodStatement::class,
                $id,
                $antes,
                null,
                null,
                $actorId,
                $correlationId,
            );
        });
    }

    /**
     * Quantas liquidações do período não têm como entrar em NENHUMA
     * conciliação: o título não tem `account_id`, porque o texto de conta na
     * origem não bateu com nenhum cadastro do Gestão.
     *
     * Não inventa conta para elas — só avisa que existem, para a funcionária
     * saber que falta corrigir o cadastro na origem. Movimento manual não
     * entra nesta conta: o campo conta é obrigatório no formulário, então
     * não existe movimento manual sem conta.
     */
    public function contarSemConta(string $from, string $to): int
    {
        return DB::table('title_settlements')
            ->join('financial_titles', 'financial_titles.id', '=', 'title_settlements.financial_title_id')
            ->where('title_settlements.status', 'CONFIRMED')
            ->whereNull('financial_titles.account_id')
            ->whereNull('financial_titles.deleted_at')
            ->where('title_settlements.settlement_date', '>=', $from)
            ->where('title_settlements.settlement_date', '<', Carbon::parse($to)->addDay()->toDateString())
            ->count();
    }

    /**
     * Quantas liquidações do período ficaram de fora desta conciliação por não
     * ter conta bancária definida.
     *
     * Devolve zero quando a empresa tem uma conta só: nesse caso a liquidação
     * sem banco entra normalmente (`bancoHerdaSemConta()`) e não há pendência
     * nenhuma a comunicar. O número só aparece quando a empresa passou a ter
     * duas ou mais contas ativas — aí a dedução deixa de valer, e alguém
     * precisa dizer por onde cada pagamento saiu.
     *
     * Avisa sem bloquear: travar o fechamento por causa disso pararia a
     * operação todo dia enquanto houvesse passivo histórico do sync.
     */
    public function contarSemContaBancaria(int $accountId, string $from, string $to, ?int $bankAccountId): int
    {
        // Num grupo mesclado, cada empresa real deduz a própria conta única
        // (`bancoHerdaSemConta` nunca roda sobre um id mesclado) — uma pode
        // estar ambígua e a outra não.
        $idsComContaAmbigua = array_values(array_filter(
            CompanyGroup::memberIds($accountId),
            fn (int $membroId): bool => ! $this->bancoHerdaSemConta($membroId, $bankAccountId),
        ));

        if ($idsComContaAmbigua === []) {
            return 0;
        }

        return DB::table('title_settlements')
            ->join('financial_titles', 'financial_titles.id', '=', 'title_settlements.financial_title_id')
            ->where('title_settlements.status', 'CONFIRMED')
            ->whereIn('financial_titles.account_id', $idsComContaAmbigua)
            ->whereNull('financial_titles.deleted_at')
            ->whereNull('title_settlements.bank_account_id')
            ->where('title_settlements.settlement_date', '>=', $from)
            ->where('title_settlements.settlement_date', '<', Carbon::parse($to)->addDay()->toDateString())
            ->count();
    }

    /**
     * Tudo que está elegível para a conta e o período agora, indexado pela
     * identidade real do movimento — a chave que `refresh()` usa para saber o
     * que é novo, o que mudou e o que saiu.
     *
     * @return array<string, array<string, mixed>>
     */
    private function elegiveis(
        int $accountId,
        string $from,
        string $to,
        ?int $bankAccountId = null,
        ?PeriodStatement $statement = null,
    ): array {
        $porChave = [];

        // Normalmente um id só. Quando `$accountId` é o id canônico de um
        // grupo mesclado (ver `CompanyGroup`, hoje só Agrocolitti), busca dos
        // dois ids reais e mescla — as chaves (`settlement:<id>`/
        // `manual:<id>`) são globalmente únicas, nunca colidem entre
        // empresas.
        foreach (CompanyGroup::memberIds($accountId) as $membroId) {
            foreach ($this->linhasDeLiquidacoes($membroId, $from, $to, $bankAccountId) as $linha) {
                $porChave['settlement:'.$linha['title_settlement_id']] = $linha;
            }
            foreach ($this->linhasDeMovimentosManuais($membroId, $from, $to, $bankAccountId) as $linha) {
                $porChave['manual:'.$linha['manual_movement_id']] = $linha;
            }
        }

        // As linhas que alguém marcou como "não passou por esta conta" saem
        // AQUI, antes de qualquer soma — assim elas não entram no saldo, nos
        // totais nem na contagem, e o `refresh()` não as traz de volta.
        foreach ($this->chavesExcluidas($statement) as $chave) {
            unset($porChave[$chave]);
        }

        return $porChave;
    }

    /** @return list<string> */
    private function chavesExcluidas(?PeriodStatement $statement): array
    {
        if ($statement === null || ! $statement->exists) {
            return [];
        }

        return $statement->exclusions()
            ->get()
            ->map(fn (PeriodStatementExclusion $e): string => $e->chave())
            ->all();
    }

    /**
     * Tira uma linha da conciliação: ela não passou por esta conta bancária.
     *
     * O caso que motivou isto: a conta do Itaú é da empresa, e nem todo
     * pagamento sai por ela — quando alguém paga por fora (PIX, outra conta do
     * grupo), o lançamento existe no Contas a Pagar mas nunca tocou aquele
     * extrato. Como as origens não guardam banco, a conciliação puxa tudo da
     * empresa e essas linhas entram indevidamente.
     *
     * Não apaga nada. Título, liquidação e movimento manual continuam onde
     * estavam; só deixam de contar neste extrato. E é reversível.
     */
    public function excluirLinha(
        PeriodStatement $statement,
        PeriodStatementLine $linha,
        ?string $motivo = null,
        ?int $actorId = null,
    ): PeriodStatement {
        if (! $statement->isOpen()) {
            throw new DomainException('Esta conciliação está fechada e não pode ser alterada.');
        }

        if ($linha->period_statement_id !== $statement->id) {
            throw new DomainException('Esta linha não pertence a esta conciliação.');
        }

        if ($linha->isPendente()) {
            throw new DomainException('O bloco "em aberto" não faz parte do saldo — não há o que remover dele.');
        }

        return DB::transaction(function () use ($statement, $linha, $motivo, $actorId): PeriodStatement {
            PeriodStatementExclusion::query()->updateOrCreate(
                [
                    'period_statement_id' => $statement->id,
                    'title_settlement_id' => $linha->title_settlement_id,
                    'manual_movement_id' => $linha->manual_movement_id,
                ],
                ['reason' => $motivo, 'excluded_by' => $actorId],
            );

            $this->audit->record(
                'PERIOD_STATEMENT_LINE_EXCLUDED',
                PeriodStatement::class,
                $statement->id,
                null,
                [
                    'history' => $linha->history,
                    'movement_date' => $linha->movement_date->toDateString(),
                    'amount_in_cents' => $linha->amount_in_cents,
                    'amount_out_cents' => $linha->amount_out_cents,
                    'title_settlement_id' => $linha->title_settlement_id,
                    'manual_movement_id' => $linha->manual_movement_id,
                    'reason' => $motivo,
                ],
                null,
                $actorId,
                (string) Str::uuid(),
            );

            $this->refresh($statement, $actorId);

            return $statement->fresh(['lines', 'exclusions']);
        });
    }

    /** Devolve para a conciliação uma linha que tinha sido tirada. */
    public function restaurarLinha(
        PeriodStatement $statement,
        PeriodStatementExclusion $exclusao,
        ?int $actorId = null,
    ): PeriodStatement {
        if (! $statement->isOpen()) {
            throw new DomainException('Esta conciliação está fechada e não pode ser alterada.');
        }

        if ($exclusao->period_statement_id !== $statement->id) {
            throw new DomainException('Esta exclusão não pertence a esta conciliação.');
        }

        return DB::transaction(function () use ($statement, $exclusao, $actorId): PeriodStatement {
            $antes = [
                'title_settlement_id' => $exclusao->title_settlement_id,
                'manual_movement_id' => $exclusao->manual_movement_id,
                'reason' => $exclusao->reason,
            ];

            $exclusao->delete();

            $this->audit->record(
                'PERIOD_STATEMENT_LINE_RESTORED',
                PeriodStatement::class,
                $statement->id,
                $antes,
                null,
                null,
                $actorId,
                (string) Str::uuid(),
            );

            $this->refresh($statement, $actorId);

            return $statement->fresh(['lines', 'exclusions']);
        });
    }

    /**
     * Reconstrói só o bloco de pendências, preservando o movimento.
     *
     * Usado quando a atualização não achou movimento novo: o que está em aberto
     * pode ter mudado mesmo assim, porque uma baixa registrada fora do período
     * tira o título do rodapé sem criar linha nenhuma aqui dentro.
     */
    private function regravarPendentes(PeriodStatement $statement): void
    {
        $movimento = $statement->lines()
            ->where('section', PeriodStatementSection::Ledger->value)
            ->count();

        $statement->lines()->where('section', PeriodStatementSection::Pending->value)->delete();

        $this->inserirLinhas($statement, $this->linhasPendentes(
            $statement->account_id,
            $statement->period_end->toDateString(),
            $statement->bank_account_id,
            $movimento,
        ));
    }

    /**
     * O rodapé da conciliação: títulos da conta que ainda NÃO caíram no banco.
     *
     * É o bloco que fecha a aba do mês nas planilhas — linhas sem data e sem
     * saldo, só documento, id de origem, histórico e valor. Elas não são
     * movimento: não entram no saldo corrido, nos totais nem no `line_count`.
     *
     * O recorte é `issue_date <= fim do período` e saldo ainda em aberto. A
     * emissão é o que decide, e não o vencimento: um título emitido em agosto
     * com vencimento em setembro JÁ é uma pendência conhecida de agosto, e é
     * exatamente assim que aparece na planilha (as últimas linhas de
     * Agosto-2026 são todas "V.02/09"). Filtrar por vencimento esconderia
     * justamente o que a conciliação existe para mostrar.
     *
     * Quando a conciliação fecha, estas linhas congelam como estão — o retrato
     * do que estava pendente naquele momento.
     *
     * @return list<array<string, mixed>>
     */
    private function linhasPendentes(int $accountId, string $to, ?int $bankAccountId, int $aPartirDe): array
    {
        // Um título em aberto não ocorreu, então não tem banco a apurar. Ele
        // pertence à conta bancária da empresa quando ela só tem uma; com duas
        // ou mais, mostrá-lo em cada conciliação repetiria o mesmo título.
        // Num grupo mesclado, cada empresa real decide isso por si — uma pode
        // qualificar e a outra não.
        $idsElegiveis = array_values(array_filter(
            CompanyGroup::memberIds($accountId),
            fn (int $membroId): bool => $this->bancoHerdaSemConta($membroId, $bankAccountId),
        ));

        if ($idsElegiveis === []) {
            return [];
        }

        $titulos = DB::table('financial_titles')
            ->whereIn('account_id', $idsElegiveis)
            ->whereNull('deleted_at')
            ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED'])
            ->where(function ($q) use ($to): void {
                // Título sem emissão registrada não é descartado: a origem
                // aceita emissão nula e o título existe do mesmo jeito.
                $q->whereNull('issue_date')
                    ->orWhere('issue_date', '<', Carbon::parse($to)->addDay()->toDateString());
            })
            ->orderBy('due_date')
            ->orderBy('id')
            ->get([
                'id', 'external_id', 'document_number', 'party_name',
                'due_date', 'type', 'total_amount',
            ]);

        if ($titulos->isEmpty()) {
            return [];
        }

        // Saldo em aberto de cada título, somado no banco: um título com baixa
        // parcial pende só pelo que falta, e é esse número que a planilha
        // mostra. REVERSAL desconta, como em FinancialTitle::settledCents().
        $baixas = DB::table('title_settlements')
            ->whereIn('financial_title_id', $titulos->pluck('id'))
            ->where('status', 'CONFIRMED')
            ->groupBy('financial_title_id')
            ->selectRaw("financial_title_id, SUM(CASE WHEN type = 'REVERSAL' THEN -amount ELSE amount END) AS baixado")
            ->pluck('baixado', 'financial_title_id');

        $numero = $aPartirDe;
        $linhas = [];

        foreach ($titulos as $t) {
            $restante = Money::toCents((string) $t->total_amount)
                - (int) round((float) ($baixas[$t->id] ?? 0) * 100);

            if ($restante <= 0) {
                continue;
            }

            $ehEntrada = $t->type === FinancialTitleType::Receivable->value;

            $linhas[] = [
                'line_number' => ++$numero,
                // Pendência não é arrastável (não tem identidade estável fora
                // do `financial_title_id`) — sempre null. Presente mesmo
                // assim porque `inserirLinhas()` insere linhas de LEDGER e de
                // PENDING no mesmo lote, e as chaves precisam bater.
                'manual_position' => null,
                'section' => PeriodStatementSection::Pending->value,
                // A coluna é NOT NULL e a linha não tem data própria; o fim do
                // período é o instante a que a pendência se refere. `section`
                // é quem diz à tela para não mostrar data nenhuma aqui.
                'movement_date' => $to,
                'document_number' => $t->document_number,
                'origin_id' => $t->external_id,
                'history' => $this->historicoPendente($t),
                'due_date' => $t->due_date === null ? null : Carbon::parse($t->due_date)->toDateString(),
                'amount_in_cents' => $ehEntrada ? $restante : null,
                'amount_out_cents' => $ehEntrada ? null : $restante,
                // Zero ignorado: pendência não tem saldo corrido. Ver a
                // migration que criou `section` para o porquê de não ser NULL.
                'running_balance_cents' => 0,
                'financial_title_id' => $t->id,
                'title_settlement_id' => null,
                'manual_movement_id' => null,
            ];
        }

        return $linhas;
    }

    /** "V 02/09 - Nome da parte", igual ao histórico do movimento. */
    private function historicoPendente(object $t): string
    {
        $partes = [];

        if ($t->due_date !== null) {
            $partes[] = 'V '.Carbon::parse($t->due_date)->format('d/m');
        }

        $nome = trim((string) $t->party_name);
        if ($nome !== '') {
            $partes[] = $nome;
        }

        $texto = implode(' - ', $partes);

        return Str::limit($texto === '' ? 'Título sem descrição' : $texto, 250, '');
    }

    private function chaveDaLinha(PeriodStatementLine $linha): string
    {
        return $linha->manual_movement_id !== null
            ? 'manual:'.$linha->manual_movement_id
            : 'settlement:'.$linha->title_settlement_id;
    }

    /**
     * Os dados cacheados na linha ainda batem com a fonte agora? Só os campos
     * que a fonte pode legitimamente mudar enquanto a conciliação está
     * ABERTA — um movimento manual corrigido é o caso real; uma liquidação
     * nunca muda depois de criada (o hash de idempotência garante isso), mas
     * a comparação é a mesma para as duas fontes, de propósito: não há dois
     * caminhos para a mesma pergunta.
     */
    private function linhaMudou(PeriodStatementLine $linha, array $dados): bool
    {
        $entradaCents = $dados['_entrada'] ? $dados['_cents'] : null;
        $saidaCents = $dados['_entrada'] ? null : $dados['_cents'];

        return $linha->movement_date->toDateString() !== $dados['movement_date']
            || (int) $linha->amount_in_cents !== (int) $entradaCents
            || (int) $linha->amount_out_cents !== (int) $saidaCents
            || $linha->history !== $dados['history']
            || (string) $linha->document_number !== (string) $dados['document_number'];
    }

    /**
     * Monta as linhas finais (numeradas, com saldo corrido) a partir do
     * conjunto já ordenado, e devolve os totais junto — usado tanto pela
     * prévia quanto pelo `refresh()`, para as duas nunca poderem divergir.
     *
     * @param  list<array<string, mixed>>  $ordenados
     * @return array{0: list<array<string, mixed>>, 1: array{closing_cents: int, total_in_cents: int, total_out_cents: int}}
     */
    /**
     * @param  list<array<string, mixed>>  $ordenados
     * @param  array<string, int>  $posicoesManuais  chave (`chaveDoArray()`) => posição fixada no dia
     */
    private function montarLinhas(array $ordenados, int $openingCents, array $posicoesManuais = []): array
    {
        $saldo = $openingCents;
        $entradas = 0;
        $saidas = 0;
        $numero = 0;
        $linhas = [];

        foreach ($ordenados as $linha) {
            $saldo += $linha['_entrada'] ? $linha['_cents'] : -$linha['_cents'];
            $linha['_entrada'] ? $entradas += $linha['_cents'] : $saidas += $linha['_cents'];

            $linhas[] = [
                'line_number' => ++$numero,
                // Recuperada pela identidade real da linha, não pela posição
                // no array — sobrevive ao rebuild porque `posicoesManuaisAtuais()`
                // já leu isto das linhas antigas antes de elas serem apagadas.
                'manual_position' => $posicoesManuais[$this->chaveDoArray($linha)] ?? null,
                'section' => PeriodStatementSection::Ledger->value,
                'movement_date' => $linha['movement_date'],
                'document_number' => $linha['document_number'],
                'origin_id' => $linha['origin_id'],
                'history' => $linha['history'],
                'due_date' => $linha['due_date'],
                'amount_in_cents' => $linha['_entrada'] ? $linha['_cents'] : null,
                'amount_out_cents' => $linha['_entrada'] ? null : $linha['_cents'],
                'running_balance_cents' => $saldo,
                'financial_title_id' => $linha['financial_title_id'],
                'title_settlement_id' => $linha['title_settlement_id'],
                'manual_movement_id' => $linha['manual_movement_id'],
            ];
        }

        return [$linhas, ['closing_cents' => $saldo, 'total_in_cents' => $entradas, 'total_out_cents' => $saidas]];
    }

    /** @param  list<array<string, mixed>>  $linhas */
    private function inserirLinhas(PeriodStatement $statement, array $linhas): void
    {
        foreach (array_chunk($linhas, 500) as $lote) {
            $agora = now();
            PeriodStatementLine::insert(array_map(
                fn (array $linha): array => $linha + [
                    'period_statement_id' => $statement->id,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ],
                $lote,
            ));
        }
    }

    /**
     * Ordena as duas fontes numa linha do tempo só, de forma determinística:
     * o mesmo conjunto de movimentos sempre produz a mesma ordem.
     *
     * Critério, em ordem de prioridade:
     *
     *  1. data financeira do movimento (liquidação/movimento manual);
     *  2. quando o movimento foi de fato REGISTRADO no Gestão
     *     (`title_settlements.created_at` / `manual_movements.created_at`).
     *     Nenhuma das origens (`contas`/`contasareceber`) guarda hora de
     *     pagamento — só data — então não existe "timestamp real da
     *     realização" para usar; o timestamp confiável mais próximo disso é
     *     quando o Gestão gravou o fato, e é ele que aqui decide a ordem
     *     dentro do mesmo dia;
     *  3. fonte (liquidação antes de movimento manual) e id, como desempate
     *     final para o caso raro de duas linhas terem o mesmo segundo de
     *     registro — uma sincronização em lote cria várias liquidações no
     *     mesmo segundo.
     *
     * Quando `$posicoesManuais` traz uma posição fixada para uma linha (ver
     * `reordenarDia()`), ela passa a decidir a ordem DENTRO do mesmo dia,
     * antes do critério padrão acima: entre duas linhas fixadas, a posição
     * decide; uma linha fixada sempre vem antes de uma que não foi tocada;
     * entre duas sem posição, o critério padrão continua valendo — inclusive
     * uma linha nova que aparece num dia já reordenado, que cai depois das
     * fixadas até alguém arrastá-la também.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @param  array<string, int>  $posicoesManuais  chave (`chaveDoArray()`) => posição fixada no dia
     * @return list<array<string, mixed>>
     */
    private function ordenar(array $linhas, array $posicoesManuais = []): array
    {
        usort($linhas, function (array $a, array $b) use ($posicoesManuais): int {
            if ($a['movement_date'] !== $b['movement_date']) {
                return $a['movement_date'] <=> $b['movement_date'];
            }

            $posicaoA = $posicoesManuais[$this->chaveDoArray($a)] ?? null;
            $posicaoB = $posicoesManuais[$this->chaveDoArray($b)] ?? null;

            if ($posicaoA !== null || $posicaoB !== null) {
                return match (true) {
                    $posicaoA !== null && $posicaoB !== null => $posicaoA <=> $posicaoB,
                    $posicaoA !== null => -1,
                    default => 1,
                };
            }

            return [$a['_registrado_em'], $a['_ordem_fonte'], $a['_id']]
                <=> [$b['_registrado_em'], $b['_ordem_fonte'], $b['_id']];
        });

        return $linhas;
    }

    /** A mesma identidade de `chaveDaLinha()`, mas para a linha ainda em array (antes de virar `PeriodStatementLine`). */
    private function chaveDoArray(array $linha): string
    {
        return $linha['manual_movement_id'] !== null
            ? 'manual:'.$linha['manual_movement_id']
            : 'settlement:'.$linha['title_settlement_id'];
    }

    /**
     * Movimentos manuais do período: PIX, tarifa, rendimento, ajuste.
     *
     * @return list<array<string, mixed>>
     */
    private function linhasDeMovimentosManuais(int $accountId, string $from, string $to, ?int $bankAccountId = null): array
    {
        // Limite superior como "< dia seguinte", e não `BETWEEN from AND to`.
        //
        // A coluna é DATE, mas o cast de data do Eloquent grava
        // "2026-01-31 00:00:00". No MariaDB isso não importa — o servidor trunca
        // e o BETWEEN pega o último dia. No SQLite, que guarda a string inteira,
        // "2026-01-31 00:00:00" > "2026-01-31" e o último dia do período some.
        // Comparar contra o dia seguinte acerta nos dois e continua usando o
        // índice, que um `whereDate` (DATE(coluna)) descartaria.
        $movimentos = DB::table('manual_movements')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->where('movement_date', '>=', $from)
            ->where('movement_date', '<', Carbon::parse($to)->addDay()->toDateString())
            // Movimento manual é a única fonte que SABE por qual banco passou,
            // porque alguém digitou. Sem banco preenchido ele pertence à conta
            // única da empresa — o caso normal, e o que a planilha assume.
            ->when($bankAccountId !== null, function ($q) use ($accountId, $bankAccountId): void {
                $q->where(function ($inner) use ($accountId, $bankAccountId): void {
                    $inner->where('bank_account_id', $bankAccountId);

                    if ($this->bancoHerdaSemConta($accountId, $bankAccountId)) {
                        $inner->orWhereNull('bank_account_id');
                    }
                });
            })
            ->get(['id', 'movement_date', 'direction', 'amount', 'history', 'document_number', 'created_at']);

        $linhas = [];

        foreach ($movimentos as $m) {
            $linhas[] = [
                'movement_date' => Carbon::parse($m->movement_date)->toDateString(),
                'document_number' => $m->document_number,
                'origin_id' => 'MANUAL',
                'history' => $m->history,
                'due_date' => null,
                'financial_title_id' => null,
                'title_settlement_id' => null,
                'manual_movement_id' => $m->id,
                '_cents' => Money::toCents((string) $m->amount),
                '_entrada' => $m->direction === ManualMovementDirection::In->value,
                '_ordem_fonte' => 1,
                '_id' => (int) $m->id,
                '_registrado_em' => Carbon::parse($m->created_at)->toDateTimeString(),
            ];
        }

        return $linhas;
    }

    /**
     * Liquidações confirmadas do período, uma linha por baixa.
     *
     * @return list<array<string, mixed>>
     */
    private function linhasDeLiquidacoes(int $accountId, string $from, string $to, ?int $bankAccountId = null): array
    {
        $movimentos = DB::table('title_settlements')
            ->join('financial_titles', 'financial_titles.id', '=', 'title_settlements.financial_title_id')
            ->where('title_settlements.status', 'CONFIRMED')
            ->where('financial_titles.account_id', $accountId)
            ->whereNull('financial_titles.deleted_at')
            // Limite superior como "< dia seguinte", e não `BETWEEN from AND to`.
            //
            // O cast `immutable_date` grava "2026-08-24 00:00:00". No MariaDB a
            // coluna é DATE e o servidor trunca; no SQLite a string fica
            // inteira e "2026-08-24 00:00:00" > "2026-08-24", então o BETWEEN
            // perde o último dia do período. Numa conciliação diária, em que
            // `from` e `to` são o mesmo dia, perde TUDO.
            ->where('title_settlements.settlement_date', '>=', $from)
            ->where('title_settlements.settlement_date', '<', Carbon::parse($to)->addDay()->toDateString())
            // O recorte agora é pelo FATO gravado na liquidação, não por
            // convenção: entra o que aponta para esta conta. A liquidação sem
            // banco entra junto só quando a empresa tem uma conta só — ver
            // `bancoHerdaSemConta()`. Com duas ou mais, ela fica de fora e é
            // contada como pendência.
            ->when($bankAccountId !== null, function ($q) use ($accountId, $bankAccountId): void {
                $q->where(function ($inner) use ($accountId, $bankAccountId): void {
                    $inner->where('title_settlements.bank_account_id', $bankAccountId);

                    if ($this->bancoHerdaSemConta($accountId, $bankAccountId)) {
                        $inner->orWhereNull('title_settlements.bank_account_id');
                    }
                });
            })
            ->orderBy('title_settlements.settlement_date')
            ->orderBy('title_settlements.id')
            ->get([
                'title_settlements.id AS settlement_id',
                'title_settlements.settlement_date',
                'title_settlements.amount',
                'title_settlements.type AS settlement_type',
                'title_settlements.created_at AS settlement_created_at',
                'financial_titles.id AS title_id',
                'financial_titles.external_id',
                'financial_titles.document_number',
                'financial_titles.party_name',
                'financial_titles.due_date',
                'financial_titles.type AS title_type',
            ]);

        $linhas = [];

        foreach ($movimentos as $m) {
            // Um estorno desfaz o movimento: inverte o sinal do lançamento
            // original em vez de virar uma linha de sentido próprio.
            $ehEntrada = $m->title_type === FinancialTitleType::Receivable->value;
            if ($m->settlement_type === 'REVERSAL') {
                $ehEntrada = ! $ehEntrada;
            }

            $linhas[] = [
                // Normalizado para Y-m-d: dependendo do driver a coluna DATE
                // volta como '2026-01-08' ou '2026-01-08 00:00:00', e a prévia
                // precisa ser idêntica ao que vai ser gravado.
                'movement_date' => Carbon::parse($m->settlement_date)->toDateString(),
                'document_number' => $m->document_number,
                'origin_id' => $m->external_id,
                'history' => $this->historico($m),
                'due_date' => $m->due_date === null ? null : Carbon::parse($m->due_date)->toDateString(),
                'financial_title_id' => $m->title_id,
                'title_settlement_id' => $m->settlement_id,
                'manual_movement_id' => null,
                '_cents' => Money::toCents((string) $m->amount),
                '_entrada' => $ehEntrada,
                '_ordem_fonte' => 0,
                '_id' => (int) $m->settlement_id,
                '_registrado_em' => Carbon::parse($m->settlement_created_at)->toDateTimeString(),
            ];
        }

        return $linhas;
    }

    /**
     * "V 30/04 - Nome da parte" — o `V` marca a data de VENCIMENTO do título,
     * que é diferente da data do movimento (a coluna DATA), como no relatório
     * do sistema antigo.
     */
    private function historico(object $m): string
    {
        $partes = [];

        if ($m->due_date !== null) {
            $partes[] = 'V '.Carbon::parse($m->due_date)->format('d/m');
        }

        $nome = trim((string) $m->party_name);
        if ($nome !== '') {
            $partes[] = $nome;
        }

        $texto = implode(' - ', $partes);

        if ($m->settlement_type === 'REVERSAL') {
            $texto = 'ESTORNO '.$texto;
        }

        return Str::limit($texto === '' ? 'Movimento sem descrição' : $texto, 250, '');
    }

    /**
     * Saldo inicial sugerido: o saldo final do último período já criado para
     * a mesma conta que termine antes deste. Sem isso, o operador teria de
     * redigitar o saldo todo mês e um erro de digitação passaria despercebido.
     *
     * Só olha conciliações FECHADAS: uma ainda ABERTA pode ter o saldo final
     * mudando a cada "Atualizar", e sugerir um número que ainda vai mudar
     * seria pior do que não sugerir nada.
     *
     * Devolve `null` quando não há o que sugerir (primeira conciliação da
     * conta) — de propósito, para não se confundir com um saldo final
     * anterior que realmente foi zero. É essa distinção que permite a tela
     * deixar o campo em branco em vez de pré-preencher "0,00" sozinha.
     */
    public function suggestedOpeningCents(int $accountId, string $from, ?int $bankAccountId = null): ?int
    {
        $anterior = PeriodStatement::query()
            ->where('account_id', $accountId)
            ->where('status', PeriodStatementStatus::Closed->value)
            // O saldo anterior é da CONTA BANCÁRIA, não da empresa: sugerir o
            // fechamento de um banco como abertura de outro daria um saldo
            // inicial errado com cara de conferido.
            ->when(
                $bankAccountId !== null,
                fn ($q) => $q->where('bank_account_id', $bankAccountId),
                fn ($q) => $q->whereNull('bank_account_id'),
            )
            ->whereDate('period_end', '<', $from)
            ->orderByDesc('period_end')
            ->first();

        return $anterior?->closing_balance_cents;
    }
}
