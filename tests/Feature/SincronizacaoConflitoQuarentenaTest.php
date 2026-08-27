<?php

namespace Tests\Feature;

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Exceptions\TitleUpdateNotAllowed;
use App\Domain\Financial\TitleIngestionData;
use App\Models\AuditEvent;
use App\Models\FinancialTitle;
use App\Models\OriginSyncConflict;
use App\Models\SourceSystem;
use App\Models\SyncCycle;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Conflito de regra não é falha técnica.
 *
 * O caso real (título 90317, em produção): a origem alterou a data de EMISSÃO
 * de um título que o Gestão já tem liquidado. A regra recusa — e está certa,
 * histórico financeiro não se reescreve por reenvio. Só que a origem reenvia o
 * mesmo dado a cada leitura, e a tarefa agendada roda de 5 em 5 minutos: o
 * ciclo terminava em ERROR e o agendador registrava resultado 1 para sempre.
 *
 * Um alarme que toca sempre é um alarme que ninguém escuta — e o dia em que a
 * falha for de verdade, ninguém vai olhar. Daí a separação em quatro desfechos:
 * sucesso, rejeição esperada, conflito/quarentena e erro técnico real.
 *
 * A correção é genérica de propósito: nada aqui conhece o id 90317.
 */
class SincronizacaoConflitoQuarentenaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome')->nullable();
            $table->string('username');
            $table->string('password')->nullable();
            $table->boolean('comercial')->default(false);
            $table->boolean('pagamentos')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function operador(): User
    {
        $user = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$user->id],
            'reconciliation.manage_user_ids' => [$user->id],
            'gestao.legacy_ui' => false,
        ]);

        return $user;
    }

    private function enviar(array $sobrescreve = []): FinancialTitle
    {
        SourceSystem::query()->firstOrCreate(
            ['code' => 'LEGACY_PAYABLE'],
            ['name' => 'Contas a pagar legadas', 'active' => true],
        );

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(...array_merge([
            'sourceCode' => 'LEGACY_PAYABLE',
            'externalId' => '90317',
            'type' => FinancialTitleType::Payable,
            'issueDate' => '2026-07-01',
            'dueDate' => '2026-07-21',
            'originalAmount' => '1200.00',
            'partyType' => 'SUPPLIER',
            'partyName' => 'Fornecedor X',
            'documentNumber' => '55010',
            'installmentCount' => 1,
        ], $sobrescreve)))->title;
    }

    private function pagar(FinancialTitle $titulo): void
    {
        app(SettlementService::class)->settle(
            titleId: $titulo->id,
            amount: '1200.00',
            settlementDate: '2026-07-21',
            installmentId: $titulo->installments()->first()?->id,
            sourceSystemId: $titulo->source_system_id,
            externalId: 'baixa-90317',
        );
    }

    /**
     * A causa geral, reproduzida: emissão alterada em título já liquidado.
     * O domínio precisa continuar recusando — a quarentena existe para lidar
     * com a recusa, não para desfazê-la.
     */
    public function test_emissao_alterada_em_titulo_liquidado_continua_sendo_recusada(): void
    {
        $titulo = $this->enviar();
        $this->pagar($titulo);

        $this->expectException(TitleUpdateNotAllowed::class);
        $this->enviar(['issueDate' => '2026-06-15']);
    }

    /** O dado protegido não pode ser sobrescrito nem por acidente. */
    public function test_dado_protegido_nao_e_sobrescrito_pelo_reenvio(): void
    {
        $titulo = $this->enviar();
        $this->pagar($titulo);

        try {
            $this->enviar(['issueDate' => '2026-06-15']);
        } catch (TitleUpdateNotAllowed) {
            // esperado
        }

        $this->assertSame('2026-07-01', $titulo->fresh()->issue_date->toDateString());
    }

    /** Mesmo conteúdo reenviado é idempotente: não é conflito, não é erro. */
    public function test_reenvio_do_mesmo_conteudo_e_idempotente(): void
    {
        $titulo = $this->enviar();
        $this->pagar($titulo);

        $mesmo = $this->enviar();

        $this->assertSame($titulo->id, $mesmo->id);
        $this->assertSame(0, OriginSyncConflict::query()->count());
    }

    /**
     * A classificação do ciclo. Conflito é visível e NÃO é falha; só erro
     * técnico é falha — é isso que decide o código de saída da tarefa agendada.
     */
    public function test_ciclo_com_conflito_nao_e_falha_tecnica(): void
    {
        $ciclo = SyncCycle::create([
            'source_code' => 'LEGACY_PAYABLE',
            'trigger' => 'scheduled',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'conflict_count' => 1,
            'error_count' => 0,
            'status' => 'CONFLICT',
        ]);

        $this->assertTrue($ciclo->hasConflicts());
        $this->assertFalse($ciclo->isFailure());
        $this->assertTrue($ciclo->finishedCleanly());
        $this->assertFalse($ciclo->isOk());
    }

    public function test_ciclo_com_erro_tecnico_continua_sendo_falha(): void
    {
        $ciclo = SyncCycle::create([
            'source_code' => 'LEGACY_PAYABLE',
            'trigger' => 'scheduled',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_count' => 3,
            'status' => 'ERROR',
        ]);

        $this->assertTrue($ciclo->isFailure());
        $this->assertFalse($ciclo->finishedCleanly());
    }

    /**
     * O motivo da rejeição deixou de ser descartado. "5 rejeitados" sem
     * explicação é um número que ninguém consegue auditar.
     */
    public function test_motivos_de_rejeicao_ficam_registrados_e_legiveis(): void
    {
        $ciclo = SyncCycle::create([
            'source_code' => 'LEGACY_PAYABLE',
            'trigger' => 'scheduled',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'source_rows_rejected' => 5,
            'rejected_summary' => json_encode(['CANCELADO_NA_ORIGEM' => 4, 'VENCIMENTO_AUSENTE' => 1]),
            'status' => 'OK',
        ]);

        $this->assertSame(
            ['CANCELADO_NA_ORIGEM' => 4, 'VENCIMENTO_AUSENTE' => 1],
            $ciclo->rejectionReasons(),
        );
    }

    /** Um conflito repetido é UMA linha com contador, não 288 linhas por dia. */
    public function test_conflito_repetido_acumula_em_uma_linha_so(): void
    {
        $conflito = OriginSyncConflict::query()->create([
            'source_code' => 'LEGACY_PAYABLE',
            'external_id' => '90317',
            'kind' => 'TitleUpdateNotAllowed',
            'reason' => 'Título liquidado ou cancelado não pode ter a emissão alterado por reenvio.',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
            'occurrences' => 1,
        ]);

        $conflito->update(['occurrences' => 288, 'last_seen_at' => now()]);

        $this->assertSame(1, OriginSyncConflict::query()->count());
        $this->assertSame(288, (int) $conflito->fresh()->occurrences);
    }

    public function test_quarentena_aparece_na_tela_para_o_operador(): void
    {
        $user = $this->operador();

        OriginSyncConflict::query()->create([
            'source_code' => 'LEGACY_PAYABLE',
            'external_id' => '90317',
            'kind' => 'TitleUpdateNotAllowed',
            'reason' => 'Título liquidado ou cancelado não pode ter a emissão alterado por reenvio.',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'occurrences' => 288,
        ]);

        $this->actingAs($user)
            ->get('/sincronizacao/quarentena')
            ->assertOk()
            ->assertSee('90317')
            ->assertSee('288');
    }

    public function test_resolver_conflito_exige_motivo_e_e_auditado(): void
    {
        $user = $this->operador();

        $conflito = OriginSyncConflict::query()->create([
            'source_code' => 'LEGACY_PAYABLE',
            'external_id' => '90317',
            'kind' => 'TitleUpdateNotAllowed',
            'reason' => 'Título liquidado ou cancelado não pode ter a emissão alterado por reenvio.',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'occurrences' => 288,
        ]);

        // Sem motivo, não resolve.
        $this->actingAs($user)
            ->post("/sincronizacao/quarentena/{$conflito->id}/resolver", [])
            ->assertSessionHasErrors('note');

        $this->assertNull($conflito->fresh()->resolved_at);

        $this->actingAs($user)
            ->post("/sincronizacao/quarentena/{$conflito->id}/resolver", [
                'note' => 'Emissao corrigida de volta na origem pelo financeiro.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($conflito->fresh()->resolved_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'ORIGIN_SYNC_CONFLICT_RESOLVED')->count());
    }
}
