<?php

namespace Tests\Feature;

use App\Application\Integration\OriginSyncService;
use App\Models\SyncCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Último ciclo" precisa ser o último gravado, não o de maior `started_at`.
 *
 * Aconteceu de verdade em produção: antes da correção de fuso horário, um
 * ciclo foi gravado com `started_at` 3h adiantado ("16:16" quando o relógio
 * real dizia "13:16"). Depois da correção, ciclos novos e corretos ("14:08")
 * continuavam sendo gravados — mas como texto, "14:08" é MENOR que "16:16", e
 * `ORDER BY started_at DESC` seguia escolhendo o ciclo velho e errado como se
 * fosse o mais recente. O painel do dashboard mostrou a sincronização de
 * horas atrás como se tivesse acabado de rodar, mesmo com uma sincronização
 * nova e bem-sucedida a segundos de distância.
 */
class OriginSyncLatestCyclesTest extends TestCase
{
    use RefreshDatabase;

    private function ciclo(string $codigo, string $startedAt, string $status = 'OK'): SyncCycle
    {
        return SyncCycle::create([
            'source_code' => $codigo,
            'trigger' => 'manual',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
            'status' => $status,
        ]);
    }

    public function test_ultimo_ciclo_e_o_de_maior_id_mesmo_com_started_at_desordenado(): void
    {
        // Reproduz exatamente o incidente: gravado ANTES (id menor), mas com
        // um started_at (16:16) que fica "no futuro" em relação ao ciclo
        // seguinte, real e correto (14:08).
        $velhoComHoraAdiantada = $this->ciclo('LEGACY_PAYABLE', '2026-08-19 16:16:00', 'ERROR');
        $novoDeVerdade = $this->ciclo('LEGACY_PAYABLE', '2026-08-19 14:08:03', 'OK');

        $this->assertTrue($velhoComHoraAdiantada->id < $novoDeVerdade->id, 'pré-condição: o antigo foi gravado primeiro');
        $this->assertTrue($velhoComHoraAdiantada->started_at->gt($novoDeVerdade->started_at), 'pré-condição: o started_at do antigo é "mais tarde"');

        $latest = app(OriginSyncService::class)->latestCycles();

        $this->assertSame($novoDeVerdade->id, $latest['LEGACY_PAYABLE']->id, 'deveria escolher o ciclo realmente mais recente, não o de started_at maior');
    }

    public function test_ultimo_ciclo_por_origem_e_independente(): void
    {
        $this->ciclo('LEGACY_PAYABLE', '2026-08-19 14:01:00');
        $pagarMaisRecente = $this->ciclo('LEGACY_PAYABLE', '2026-08-19 14:08:03');
        $this->ciclo('LEGACY_RECEIVABLE', '2026-08-19 14:01:43');
        $receberMaisRecente = $this->ciclo('LEGACY_RECEIVABLE', '2026-08-19 14:08:17');

        $latest = app(OriginSyncService::class)->latestCycles();

        $this->assertSame($pagarMaisRecente->id, $latest['LEGACY_PAYABLE']->id);
        $this->assertSame($receberMaisRecente->id, $latest['LEGACY_RECEIVABLE']->id);
    }

    public function test_sem_nenhum_ciclo_devolve_nulo(): void
    {
        $latest = app(OriginSyncService::class)->latestCycles();

        $this->assertNull($latest['LEGACY_PAYABLE']);
        $this->assertNull($latest['LEGACY_RECEIVABLE']);
    }
}
