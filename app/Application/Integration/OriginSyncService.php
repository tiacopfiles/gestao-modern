<?php

namespace App\Application\Integration;

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\IngestionDecision;
use App\Domain\Financial\Exceptions\TitleTypeChangeNotAllowed;
use App\Domain\Financial\Exceptions\TitleUpdateNotAllowed;
use App\Domain\Financial\TitleIngestionData;
use App\Integration\OriginExtractor;
use App\Integration\OriginReader;
use App\Models\FinancialTitle;
use App\Models\OriginSyncConflict;
use App\Models\SourceSystem;
use App\Models\SyncCycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sincroniza os títulos das origens legadas para o banco próprio do Gestão.
 *
 * Direção única: ORIGENS → GESTÃO. A origem é aberta pelo OriginReader, que
 * recusa qualquer instrução que não seja de leitura e abre a sessão como
 * READ ONLY. Este serviço nunca emite escrita contra os bancos de origem.
 *
 * Cada execução grava um SyncCycle com o snapshot do que foi efetivamente lido,
 * porque a origem continua sendo alterada pelas funcionárias enquanto lemos —
 * sem isso, uma mudança legítima é indistinguível de um defeito.
 */
class OriginSyncService
{
    /** @var list<array{db: string, source: string, type: FinancialTitleType}> */
    private const SYSTEMS = [
        ['db' => 'contas', 'source' => 'LEGACY_PAYABLE', 'type' => FinancialTitleType::Payable],
        ['db' => 'contasareceber', 'source' => 'LEGACY_RECEIVABLE', 'type' => FinancialTitleType::Receivable],
    ];

    public function __construct(
        private readonly TitleIngestionService $ingestion,
        private readonly SettlementService $settlements,
    ) {}

    /**
     * @return list<SyncCycle> um ciclo por origem
     */
    public function sync(
        string $from,
        string $to,
        string $trigger = 'manual',
        ?int $actorId = null,
    ): array {
        $this->assertDestinationIsNotAnOrigin();

        $accounts = $this->canonicalAccounts();
        $categorias = $this->lookup('categorias');
        $centros = $this->lookup('centrocusto');
        $cycles = [];

        foreach (self::SYSTEMS as $system) {
            $cycles[] = $this->syncOne($system, $from, $to, $trigger, $actorId, $accounts, $categorias, $centros);
        }

        return $cycles;
    }

    /**
     * Bancos que jamais podem ser destino de escrita do Gestão: as duas origens,
     * suas cópias, e o Gestão legado que já está publicado no servidor.
     */
    public const FORBIDDEN_DESTINATIONS = [
        'contas',                     // ORIGEM — contas a pagar
        'contasareceber',             // ORIGEM — contas a receber
        'contasareceber_homologacao', // cópia da origem
        'contasareceber_review_qa',   // cópia da origem
        'contas_agrocolitti',         // outro sistema
        'gestao',                     // Gestão legado já publicado
    ];

    /**
     * Guard puro, sem dependência de conexão ativa, para poder ser exercitado
     * por teste sem derrubar o banco da suíte.
     *
     * @throws RuntimeException quando o destino não é um banco próprio do Gestão
     */
    public static function assertDestinationIsWritable(string $database): void
    {
        $target = mb_strtolower(basename($database));

        if (in_array($target, self::FORBIDDEN_DESTINATIONS, true)) {
            throw new RuntimeException(
                "Destino '{$target}' é banco de origem ou sistema já publicado. O Gestão nunca escreve nele."
            );
        }

        if (! str_contains($target, 'integracao') && ! str_contains($target, 'gestao')) {
            throw new RuntimeException(
                "Destino '{$target}' não parece ser um banco próprio do Gestão. ".
                "Esperado um nome contendo 'gestao' ou 'integracao'."
            );
        }
    }

    /**
     * O destino jamais pode ser um banco de origem nem o Gestão legado já
     * publicado. Guard idêntico ao do script de importação, para que o botão da
     * interface e a tarefa agendada tenham exatamente a mesma proteção.
     */
    private function assertDestinationIsNotAnOrigin(): void
    {
        $connection = (string) config('database.default');

        self::assertDestinationIsWritable(
            (string) config("database.connections.{$connection}.database")
        );
    }

    private function reader(string $database): OriginReader
    {
        return new OriginReader(
            (string) config('integration.origin.host', env('ORIGIN_DB_HOST', '127.0.0.1')),
            (int) config('integration.origin.port', env('ORIGIN_DB_PORT', 3306)),
            $database,
            (string) config('integration.origin.user', env('ORIGIN_DB_USER', 'ROOT')),
            (string) config('integration.origin.password', env('ORIGIN_DB_PASSWORD', '')),
        );
    }

    /**
     * Os ids de conta colidem entre os dois bancos de origem (o mesmo 'Marco' é
     * 1 num e 16 no outro), então a conta do Gestão é resolvida pelo NOME.
     *
     * @return array<string, string>
     */
    private function canonicalAccounts(): array
    {
        // `contas` é herdada do sistema antigo e não tem migration própria: numa
        // base criada do zero é a sincronização que precisa criá-la, já com
        // `banco` — o cabeçalho do movimento do período depende dessa coluna.
        if (! Schema::hasTable('contas')) {
            Schema::create('contas', function ($table): void {
                $table->increments('id');
                $table->string('nome');
                $table->string('banco', 120)->nullable();
                $table->string('nome_detalhado')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $canonical = [];

        foreach (self::SYSTEMS as $system) {
            $extractor = new OriginExtractor(
                $this->reader($system['db']),
                $system['source'],
                $system['type']->value,
            );

            foreach (array_keys($extractor->accounts()) as $name) {
                if (isset($canonical[$name])) {
                    continue;
                }

                $existing = DB::table('contas')->where('nome', $name)->first();
                $canonical[$name] = (string) ($existing
                    ? (int) $existing->id
                    : (int) DB::table('contas')->insertGetId([
                        'nome' => $name, 'created_at' => now(), 'updated_at' => now(),
                    ]));
            }
        }

        return $canonical;
    }

    /**
     * @param  array{db: string, source: string, type: FinancialTitleType}  $system
     * @param  array<string, string>  $accounts
     */
    private function syncOne(
        array $system,
        string $from,
        string $to,
        string $trigger,
        ?int $actorId,
        array $accounts,
        array $categorias = [],
        array $centros = [],
    ): SyncCycle {
        $cycle = SyncCycle::create([
            'source_code' => $system['source'],
            'trigger' => $trigger,
            'actor_id' => $actorId,
            'period_from' => $from,
            'period_to' => $to,
            'started_at' => Carbon::now(),
            'status' => 'RUNNING',
        ]);

        $errors = [];
        $rejections = [];

        try {
            $extractor = new OriginExtractor(
                $this->reader($system['db']),
                $system['source'],
                $system['type']->value,
            );

            $rows = $extractor->fetch($from, $to);
            $cycle->source_read_completed_at = Carbon::now();
            $cycle->source_rows_read = count($rows);

            $source = SourceSystem::query()->where('code', $system['source'])->firstOrFail();

            $mappable = 0;
            $rejected = 0;
            $totalCents = 0;
            $openCents = 0;
            $settledCents = 0;
            $created = 0;
            $updated = 0;
            $unchanged = 0;
            $settled = 0;
            $errorCount = 0;
            $conflictCount = 0;

            foreach ($rows as $row) {
                $mapped = $extractor->map($row, $accounts);

                if (! $mapped['ok']) {
                    $rejected++;
                    // O extrator sempre soube POR QUE recusou a linha
                    // (SITUACAO_NULA, VENCIMENTO_AUSENTE, CANCELADO_NA_ORIGEM,
                    // VALOR_ZERO_OU_NEGATIVO). Descartar esse motivo transformava
                    // "5 rejeitados" num número que ninguém conseguia explicar
                    // nem auditar. Agregado por motivo, ele vira informação.
                    $motivo = (string) ($mapped['reason'] ?? 'NAO_INFORMADO');
                    $rejections[$motivo] = ($rejections[$motivo] ?? 0) + 1;

                    continue;
                }

                $mappable++;
                $payload = $mapped['payload'];
                $cents = $extractor->cents($payload['original_amount']);
                $totalCents += $cents;
                $mapped['settlement'] !== null ? $settledCents += $cents : $openCents += $cents;

                try {
                    $result = $this->ingestion->ingest(new TitleIngestionData(
                        sourceCode: $system['source'],
                        externalId: $payload['external_id'],
                        type: $system['type'],
                        issueDate: $payload['issue_date'],
                        dueDate: $payload['due_date'],
                        originalAmount: $payload['original_amount'],
                        discountAmount: $payload['discount_amount'],
                        additionAmount: $payload['addition_amount'],
                        partyType: $payload['party']['type'] ?? null,
                        partyName: $payload['party']['name'] ?? null,
                        documentNumber: $payload['document_number'] ?? null,
                        accountId: $payload['account_id'] ?? null,
                        currency: 'BRL',
                        notes: $payload['notes'] ?? null,
                        installmentCount: 1,
                    ), $actorId, 'sync-'.$cycle->id.'-'.$payload['external_id']);

                    match ($result->decision) {
                        IngestionDecision::Created => $created++,
                        IngestionDecision::Updated => $updated++,
                        default => $unchanged++,
                    };

                    $this->aplicarClassificacao($result->title, $payload, $categorias, $centros);

                    if ($mapped['settlement'] !== null) {
                        $title = $result->title->load('installments');

                        if ($title->remainingCents() > 0) {
                            $this->settlements->settle(
                                titleId: $title->id,
                                amount: $mapped['settlement']['amount'],
                                settlementDate: $mapped['settlement']['settlement_date'],
                                installmentId: $title->installments->first()?->id,
                                sourceSystemId: $source->id,
                                externalId: 'baixa-'.$payload['external_id'],
                            );
                            $settled++;
                        }
                    }
                } catch (TitleUpdateNotAllowed|TitleTypeChangeNotAllowed $e) {
                    // CONFLITO DE REGRA, não falha do sistema.
                    //
                    // A origem alterou um campo protegido de um título que o
                    // Gestão já tem liquidado ou cancelado. Recusar é o
                    // comportamento correto — o histórico financeiro não pode
                    // ser reescrito por reenvio. Mas a origem continua mandando
                    // o mesmo dado a cada leitura, então tratar isso como erro
                    // técnico condenava a tarefa agendada a falhar para sempre
                    // por um caso conhecido e já decidido.
                    //
                    // O conflito vai para a quarentena, com identidade própria
                    // e contador, e o ciclo segue processando o resto.
                    $conflictCount++;
                    $this->registrarConflito(
                        $system['source'],
                        (string) $payload['external_id'],
                        $e,
                        $cycle->id,
                    );
                } catch (Throwable $e) {
                    // Erro técnico de verdade: banco fora, bug, timeout. Este
                    // ainda derruba o ciclo, e deve mesmo — esconder aqui seria
                    // trocar um alarme barulhento por um silêncio perigoso.
                    //
                    // O external_id entra no registro de propósito: um erro
                    // anônimo ("1 erro") é inútil para quem precisa conferir o
                    // título na origem.
                    $errorCount++;
                    $key = mb_substr($e->getMessage(), 0, 120);
                    $errors[$key]['total'] = ($errors[$key]['total'] ?? 0) + 1;
                    $errors[$key]['external_ids'] ??= [];

                    if (count($errors[$key]['external_ids']) < 25) {
                        $errors[$key]['external_ids'][] = $payload['external_id'];
                    }
                }
            }

            $cycle->fill([
                'source_rows_mappable' => $mappable,
                'source_rows_rejected' => $rejected,
                'source_total_cents' => $totalCents,
                'source_open_cents' => $openCents,
                'source_settled_cents' => $settledCents,
                'created_count' => $created,
                'updated_count' => $updated,
                'unchanged_count' => $unchanged,
                'settlements_created' => $settled,
                'error_count' => $errorCount,
                'conflict_count' => $conflictCount,
                'finished_at' => Carbon::now(),
                // Quatro desfechos, nesta precedência: erro técnico manda em
                // tudo; sem ele, conflito de regra é visível mas não é falha;
                // sem nenhum dos dois, o ciclo é limpo.
                'status' => match (true) {
                    $errorCount > 0 => 'ERROR',
                    $conflictCount > 0 => 'CONFLICT',
                    default => 'OK',
                },
                'error_summary' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE),
                'rejected_summary' => $rejections === [] ? null : json_encode($rejections, JSON_UNESCAPED_UNICODE),
            ]);
            $cycle->save();
        } catch (Throwable $e) {
            $cycle->fill([
                'finished_at' => Carbon::now(),
                'status' => 'ERROR',
                'error_summary' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            $cycle->save();
        }

        return $cycle->fresh();
    }

    /**
     * Põe o título em quarentena, ou atualiza o conflito que já existia.
     *
     * A identidade é `(source_code, external_id)` — um título conflitante é uma
     * linha só que se repete, com contador e primeira/última ocorrência. Sem
     * isso, 5 minutos de tarefa agendada gerariam 288 registros por dia do
     * mesmo problema, e a fila viraria ruído em vez de trabalho.
     *
     * Reaparecer reabre: se alguém marcou como resolvido mas a origem voltou a
     * mandar a mesma alteração proibida, o conflito não foi resolvido de fato.
     */
    private function registrarConflito(
        string $sourceCode,
        string $externalId,
        Throwable $e,
        int $cycleId,
    ): void {
        $agora = Carbon::now();

        $titleId = FinancialTitle::query()
            ->where('external_id', $externalId)
            ->whereNull('deleted_at')
            ->value('id');

        $conflito = OriginSyncConflict::query()->firstOrNew([
            'source_code' => $sourceCode,
            'external_id' => $externalId,
        ]);

        $conflito->fill([
            'financial_title_id' => $titleId,
            'kind' => class_basename($e),
            'reason' => mb_substr($e->getMessage(), 0, 250),
            'last_seen_at' => $agora,
            'last_sync_cycle_id' => $cycleId,
            'occurrences' => $conflito->exists ? (int) $conflito->occurrences + 1 : 1,
            'first_seen_at' => $conflito->first_seen_at ?? $agora,
        ]);

        if ($conflito->exists && $conflito->resolved_at !== null) {
            $conflito->fill([
                'resolved_at' => null,
                'resolved_by' => null,
                'resolution_note' => null,
            ]);
        }

        $conflito->save();
    }

    /**
     * Grava categoria e centro de custo direto, fora do fluxo de ingestão.
     *
     * A ingestão recusa alterar título já liquidado — e com razão: aquela regra
     * protege valor, vencimento e datas de um título cujo dinheiro já se moveu.
     * Categoria e centro de custo são CLASSIFICAÇÃO, não dinheiro; passá-los pelo
     * payload mudaria o hash de todos os títulos e faria 11 mil liquidados
     * falharem na sincronização por uma mudança que não é financeira.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $categorias
     * @param  array<string, int>  $centros
     */
    private function aplicarClassificacao(
        FinancialTitle $title,
        array $payload,
        array $categorias,
        array $centros,
    ): void {
        $categoriaId = $categorias[$this->chaveNome($payload['categoria_nome'] ?? null)] ?? null;
        $centroId = $centros[$this->chaveNome($payload['centro_custo_nome'] ?? null)] ?? null;

        $mudancas = [];
        if ($categoriaId !== null && (int) $title->category_id !== $categoriaId) {
            $mudancas['category_id'] = $categoriaId;
        }
        if ($centroId !== null && (int) $title->cost_center_id !== $centroId) {
            $mudancas['cost_center_id'] = $centroId;
        }

        if ($mudancas !== []) {
            // Update direto de propósito: não mexe em payload_hash, não dispara
            // a regra de título liquidado e não conta como alteração do título.
            DB::table('financial_titles')->where('id', $title->id)->update($mudancas);
        }
    }

    /**
     * Índice nome normalizado → id de um cadastro simples.
     *
     * @return array<string, int>
     */
    private function lookup(string $tabela): array
    {
        if (! Schema::hasTable($tabela)) {
            return [];
        }

        $mapa = [];
        foreach (DB::table($tabela)->get(['id', 'nome']) as $linha) {
            $chave = $this->chaveNome((string) $linha->nome);
            if ($chave !== null) {
                $mapa[$chave] = (int) $linha->id;
            }
        }

        return $mapa;
    }

    private function chaveNome(?string $nome): ?string
    {
        $texto = Str::of((string) $nome)->lower()->ascii()->squish()->toString();

        return $texto === '' ? null : $texto;
    }

    /**
     * Último ciclo de cada origem, para o indicador do dashboard.
     *
     * Por `id`, não por `started_at`. O `id` é auto-incremento e reflete a
     * ordem real de gravação sempre; `started_at` é calculado por `now()` no
     * momento do ciclo e pode estar errado por um motivo que já aconteceu
     * neste projeto — antes da correção de fuso horário, alguns ciclos foram
     * gravados com hora adiantada (o bug fazia `now()` calcular em UTC). Essas
     * linhas antigas continuam no banco com o valor errado (não foram
     * corrigidas retroativamente, de propósito — não é decisão para tomar
     * sozinho), e enquanto o relógio real do dia não alcança aquele horário
     * adiantado, `ORDER BY started_at DESC` escolhia a linha velha e errada
     * como se fosse a mais nova. Ordenar por `id` não depende de nenhum
     * relógio: a última linha gravada é sempre a de maior id.
     *
     * @return array<string, SyncCycle|null>
     */
    public function latestCycles(): array
    {
        $latest = [];

        foreach (self::SYSTEMS as $system) {
            $latest[$system['source']] = SyncCycle::query()
                ->where('source_code', $system['source'])
                ->orderByDesc('id')
                ->first();
        }

        return $latest;
    }
}
