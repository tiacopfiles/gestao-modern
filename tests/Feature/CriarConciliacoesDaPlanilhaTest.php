<?php

namespace Tests\Feature;

use App\Application\Financial\ManualMovementService;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\PeriodStatement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Criação das conciliações mensais a partir dos saldos das planilhas.
 *
 * O saldo de ABERTURA vem da planilha — é o que o banco disse. O de
 * FECHAMENTO nunca: ele é recalculado do que o sistema tem, e a diferença
 * entre os dois é justamente o que precisa ficar visível.
 */
class CriarConciliacoesDaPlanilhaTest extends TestCase
{
    use RefreshDatabase;

    private int $contaId;

    private BankAccount $itau;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome');
            $table->string('banco', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->contaId = (int) Conta::query()->create(['nome' => 'Acop Files'])->id;
        $this->itau = BankAccount::query()->create([
            'company_id' => $this->contaId, 'company_name' => 'Acop Files',
            'bank_name' => 'Banco Itaú', 'bank_code' => '341',
            'agency' => '6260', 'number' => '13377-9',
            'active' => true, 'is_default' => true,
        ]);

        $this->arquivo = tempnam(sys_get_temp_dir(), 'sal').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        parent::tearDown();
    }

    private function arquivoCom(array $abas): string
    {
        file_put_contents($this->arquivo, json_encode($abas));

        return $this->arquivo;
    }

    private function aba(array $sobrescreve = []): array
    {
        return array_merge([
            'empresa' => 'Acop Files',
            'aba' => 'Janeiro-2026',
            'mes' => 1,
            'abertura' => 107111.97,
            'fechamento' => 47020.18,
        ], $sobrescreve);
    }

    public function test_cria_a_conciliacao_do_mes_com_o_saldo_da_planilha(): void
    {
        $this->artisan('gestao:criar-conciliacoes-planilha', ['arquivo' => $this->arquivoCom([$this->aba()])])
            ->assertSuccessful();

        $s = PeriodStatement::query()->first();

        $this->assertNotNull($s);
        $this->assertSame(10711197, $s->opening_balance_cents);
        $this->assertSame('2026-01-01', $s->period_start->toDateString());
        $this->assertSame('2026-01-31', $s->period_end->toDateString());
        $this->assertSame($this->itau->id, (int) $s->bank_account_id);
    }

    /**
     * O ponto que não pode ser afrouxado: o fechamento é do SISTEMA, não da
     * planilha. Sem movimento nenhum, fechar igual à abertura é o correto —
     * e a diferença contra a planilha é o que fica para ser investigado.
     */
    public function test_fechamento_e_calculado_e_nao_copiado_da_planilha(): void
    {
        $this->artisan('gestao:criar-conciliacoes-planilha', ['arquivo' => $this->arquivoCom([$this->aba()])])
            ->assertSuccessful();

        $s = PeriodStatement::query()->first();

        $this->assertSame(10711197, $s->closing_balance_cents, 'sem movimento, fecha na abertura');
        $this->assertNotSame(4702018, $s->closing_balance_cents, 'não copiou o fechamento da planilha');
    }

    public function test_movimento_manual_do_mes_entra_no_fechamento(): void
    {
        app(ManualMovementService::class)->create([
            'account_id' => $this->contaId,
            'movement_date' => '2026-01-05',
            'direction' => 'OUT',
            'amount' => '119.40',
            'history' => 'TAR Cobrança EXP',
        ]);

        $this->artisan('gestao:criar-conciliacoes-planilha', ['arquivo' => $this->arquivoCom([$this->aba()])])
            ->assertSuccessful();

        $this->assertSame(10711197 - 11940, PeriodStatement::query()->first()->closing_balance_cents);
    }

    public function test_rodar_duas_vezes_nao_cria_duplicada(): void
    {
        $arquivo = $this->arquivoCom([$this->aba()]);

        $this->artisan('gestao:criar-conciliacoes-planilha', ['arquivo' => $arquivo])->assertSuccessful();
        $this->artisan('gestao:criar-conciliacoes-planilha', ['arquivo' => $arquivo])->assertSuccessful();

        $this->assertSame(1, PeriodStatement::query()->count());
    }

    public function test_modo_seco_nao_cria_nada(): void
    {
        $this->artisan('gestao:criar-conciliacoes-planilha', [
            'arquivo' => $this->arquivoCom([$this->aba()]),
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, PeriodStatement::query()->count());
    }

    public function test_meses_seguidos_viram_conciliacoes_separadas(): void
    {
        $this->artisan('gestao:criar-conciliacoes-planilha', ['arquivo' => $this->arquivoCom([
            $this->aba(['aba' => 'Janeiro-2026', 'mes' => 1]),
            $this->aba(['aba' => 'Fevereiro-2026', 'mes' => 2, 'abertura' => 47020.18]),
        ])])->assertSuccessful();

        $this->assertSame(2, PeriodStatement::query()->count());
        $this->assertSame(
            '2026-02-28',
            PeriodStatement::query()->orderByDesc('period_start')->first()->period_end->toDateString(),
        );
    }

    public function test_empresa_desconhecida_e_pulada(): void
    {
        $this->artisan('gestao:criar-conciliacoes-planilha', ['arquivo' => $this->arquivoCom([
            $this->aba(['empresa' => 'Nao Existe']),
        ])])->assertSuccessful();

        $this->assertSame(0, PeriodStatement::query()->count());
    }

    public function test_aba_sem_saldo_inicial_e_pulada(): void
    {
        $this->artisan('gestao:criar-conciliacoes-planilha', ['arquivo' => $this->arquivoCom([
            $this->aba(['abertura' => null]),
        ])])->assertSuccessful();

        $this->assertSame(0, PeriodStatement::query()->count());
    }
}
