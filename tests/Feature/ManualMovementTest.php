<?php

namespace Tests\Feature;

use App\Application\Financial\ManualMovementService;
use App\Application\Financial\PeriodStatementService;
use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Models\AuditEvent;
use App\Models\Conta;
use App\Models\FinancialTitle;
use App\Models\ManualMovement;
use App\Models\SourceSystem;
use App\Models\TitleSettlement;
use App\Models\User;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Movimento manual: PIX, tarifa, rendimento, ajuste.
 *
 * O que precisa ficar provado aqui é o que o usuário pediu em voz alta: que o
 * lançamento entra no saldo do período, que ele NÃO cria título nem baixa, e
 * que a conta fecha matematicamente.
 */
class ManualMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private int $contaId;

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
        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome');
            $table->string('banco', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->contaId = (int) Conta::query()->create(['nome' => 'Acop Files'])->id;
        $this->operador = User::query()->create([
            'nome' => 'Operadora', 'username' => 'operadora', 'password' => bcrypt('secret'),
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operador->id],
            'reconciliation.manage_user_ids' => [$this->operador->id],
            'gestao.legacy_ui' => false,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function movimento(string $direcao, string $valor, string $data, string $historico = 'PIX', array $extra = []): ManualMovement
    {
        return app(ManualMovementService::class)->create([
            'account_id' => $this->contaId,
            'movement_date' => $data,
            'direction' => $direcao,
            'amount' => $valor,
            'history' => $historico,
        ] + $extra, $this->operador->id);
    }

    private function tituloLiquidado(FinancialTitleType $tipo, string $valor, string $externo, string $dataPagamento): FinancialTitle
    {
        $codigo = $tipo === FinancialTitleType::Payable ? 'LEGACY_PAYABLE' : 'LEGACY_RECEIVABLE';
        SourceSystem::query()->firstOrCreate(['code' => $codigo], ['name' => 'Origem', 'active' => true]);

        $titulo = app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: $codigo,
            externalId: $externo,
            type: $tipo,
            issueDate: '2025-12-01',
            dueDate: '2026-01-20',
            originalAmount: $valor,
            discountAmount: '0.00',
            additionAmount: '0.00',
            partyName: 'Parte '.$externo,
            documentNumber: 'DOC-'.$externo,
            accountId: $this->contaId,
            currency: 'BRL',
            installmentCount: 1,
        ))->title;

        app(SettlementService::class)->settle(
            titleId: $titulo->id,
            amount: $valor,
            settlementDate: $dataPagamento,
            installmentId: $titulo->installments()->first()?->id,
            sourceSystemId: $titulo->source_system_id,
            externalId: 'liq-'.$externo,
        );

        return $titulo;
    }

    /**
     * O caso que o usuário escreveu no pedido, número por número.
     */
    public function test_saldo_fecha_com_entrada_e_saida_manuais(): void
    {
        $this->movimento('IN', '2500.00', '2026-08-19', 'PIX recebido - Cliente');
        $this->movimento('OUT', '1000.00', '2026-08-19', 'PIX enviado');

        $previa = app(PeriodStatementService::class)
            ->preview($this->contaId, '2026-08-01', '2026-08-31', 1000000); // R$ 10.000,00

        $this->assertSame(250000, $previa['total_in_cents']);
        $this->assertSame(100000, $previa['total_out_cents']);
        $this->assertSame(1150000, $previa['closing_cents']); // R$ 11.500,00

        // Saldo final = saldo inicial + entradas - saídas, sem sobra de centavo.
        $esperado = $previa['opening_cents'] + $previa['total_in_cents'] - $previa['total_out_cents'];
        $this->assertSame(0, $esperado - $previa['closing_cents']);
    }

    public function test_entrada_manual_soma_e_saida_manual_desconta_no_saldo_corrido(): void
    {
        $this->movimento('IN', '2500.00', '2026-08-10', 'PIX recebido');
        $this->movimento('OUT', '850.00', '2026-08-12', 'PIX enviado');

        $previa = app(PeriodStatementService::class)
            ->preview($this->contaId, '2026-08-01', '2026-08-31', 1000000);

        $this->assertCount(2, $previa['lines']);

        $this->assertSame(250000, $previa['lines'][0]['amount_in_cents']);
        $this->assertNull($previa['lines'][0]['amount_out_cents']);
        $this->assertSame(1250000, $previa['lines'][0]['running_balance_cents']);

        $this->assertNull($previa['lines'][1]['amount_in_cents']);
        $this->assertSame(85000, $previa['lines'][1]['amount_out_cents']);
        $this->assertSame(1165000, $previa['lines'][1]['running_balance_cents']);
    }

    /**
     * A regra que impede o movimento manual de virar título fantasma.
     */
    public function test_movimento_manual_nao_cria_titulo_nem_baixa(): void
    {
        $this->movimento('IN', '2500.00', '2026-08-19', 'PIX recebido');

        $this->assertSame(0, FinancialTitle::query()->count());
        $this->assertSame(0, TitleSettlement::query()->count());
        $this->assertSame(1, ManualMovement::query()->count());
    }

    /**
     * As três fontes na mesma linha do tempo: recebimento, pagamento e manual.
     */
    public function test_conciliacao_consolida_titulos_e_movimentos_manuais(): void
    {
        $this->tituloLiquidado(FinancialTitleType::Receivable, '7000.00', 'r1', '2026-01-08');
        $this->movimento('OUT', '35.00', '2026-01-09', 'Tarifa de manutenção');
        $this->tituloLiquidado(FinancialTitleType::Payable, '2000.00', 'p1', '2026-01-15');
        $this->movimento('IN', '12.34', '2026-01-31', 'Rendimento');

        $previa = app(PeriodStatementService::class)
            ->preview($this->contaId, '2026-01-01', '2026-01-31', 0);

        $this->assertCount(4, $previa['lines']);

        // Ordem cronológica, misturando as duas fontes.
        $this->assertSame(['2026-01-08', '2026-01-09', '2026-01-15', '2026-01-31'],
            array_column($previa['lines'], 'movement_date'));

        // A numeração é contínua e não reinicia por fonte.
        $this->assertSame([1, 2, 3, 4], array_column($previa['lines'], 'line_number'));

        // Só a linha manual guarda o vínculo com o movimento manual.
        $this->assertNotNull($previa['lines'][1]['manual_movement_id']);
        $this->assertNull($previa['lines'][1]['financial_title_id']);
        $this->assertNull($previa['lines'][0]['manual_movement_id']);
        $this->assertNotNull($previa['lines'][0]['financial_title_id']);

        // 7000 recebido - 35 tarifa - 2000 pago + 12,34 rendimento
        $this->assertSame(701234, $previa['total_in_cents']);
        $this->assertSame(203500, $previa['total_out_cents']);
        $this->assertSame(497734, $previa['closing_cents']);
    }

    public function test_relatorio_gravado_guarda_as_linhas_manuais(): void
    {
        $this->movimento('IN', '2500.00', '2026-08-19', 'PIX recebido - Cliente');

        $statement = app(PeriodStatementService::class)
            ->create($this->contaId, '2026-08-01', '2026-08-31', 1000000, $this->operador->id);

        $linha = $statement->lines()->first();

        $this->assertSame(1, $statement->line_count);
        $this->assertSame(250000, $linha->amount_in_cents);
        $this->assertSame('PIX recebido - Cliente', $linha->history);
        $this->assertSame('MANUAL', $linha->origin_id);
        $this->assertNotNull($linha->manual_movement_id);
        $this->assertSame(1250000, $statement->closing_balance_cents);
    }

    /**
     * Criar não deixa a conciliação "ao vivo": um movimento manual lançado
     * depois só entra quando alguém pedir explicitamente para atualizar — sem
     * isso, o registro gravado não muda sozinho.
     */
    public function test_movimento_criado_depois_nao_altera_relatorio_sem_atualizar(): void
    {
        $this->movimento('IN', '1000.00', '2026-08-05', 'PIX inicial');
        $statement = app(PeriodStatementService::class)
            ->create($this->contaId, '2026-08-01', '2026-08-31', 0, $this->operador->id);

        $this->movimento('IN', '9999.00', '2026-08-20', 'PIX posterior');

        $statement->refresh();
        $this->assertSame(1, $statement->line_count);
        $this->assertSame(100000, $statement->closing_balance_cents);
    }

    public function test_criar_editar_e_excluir_ficam_na_auditoria(): void
    {
        $movimento = $this->movimento('IN', '2500.00', '2026-08-19', 'PIX recebido');

        $criacao = AuditEvent::query()->where('action', 'MANUAL_MOVEMENT_CREATED')->first();
        $this->assertNotNull($criacao);
        $this->assertSame($this->operador->id, $criacao->actor_id);
        $this->assertSame('2500.00', $criacao->after_state['amount']);
        $this->assertSame('Entrada', $criacao->after_state['direction_label']);

        app(ManualMovementService::class)->update($movimento, [
            'account_id' => $this->contaId,
            'movement_date' => '2026-08-19',
            'direction' => 'IN',
            'amount' => '2600.00',
            'history' => 'PIX recebido - corrigido',
        ], $this->operador->id);

        $edicao = AuditEvent::query()->where('action', 'MANUAL_MOVEMENT_UPDATED')->first();
        $this->assertSame('2500.00', $edicao->before_state['amount']);
        $this->assertSame('2600.00', $edicao->after_state['amount']);

        app(ManualMovementService::class)->delete($movimento->fresh(), $this->operador->id);

        $exclusao = AuditEvent::query()->where('action', 'MANUAL_MOVEMENT_DELETED')->first();
        $this->assertSame('2600.00', $exclusao->before_state['amount']);
        $this->assertNull($exclusao->after_state);
        $this->assertSame(0, ManualMovement::query()->count());
    }

    public function test_movimento_excluido_sai_do_saldo(): void
    {
        $movimento = $this->movimento('IN', '2500.00', '2026-08-19', 'PIX recebido');
        app(ManualMovementService::class)->delete($movimento, $this->operador->id);

        $previa = app(PeriodStatementService::class)
            ->preview($this->contaId, '2026-08-01', '2026-08-31', 1000000);

        $this->assertCount(0, $previa['lines']);
        $this->assertSame(1000000, $previa['closing_cents']);
    }

    /**
     * Enquanto a conciliação está ABERTA, corrigir o movimento é permitido —
     * é para isso que o ciclo aberto existe. A trava só entra depois que a
     * conciliação é FECHADA: aí sim, corrigir faria o retrato definitivo
     * discordar da sua própria origem sem ninguém perceber.
     */
    public function test_movimento_pode_ser_alterado_enquanto_a_conciliacao_esta_aberta(): void
    {
        $movimento = $this->movimento('IN', '2500.00', '2026-08-19', 'PIX recebido');
        app(PeriodStatementService::class)
            ->create($this->contaId, '2026-08-01', '2026-08-31', 0, $this->operador->id);

        $atualizado = app(ManualMovementService::class)->update($movimento->fresh(), [
            'account_id' => $this->contaId,
            'movement_date' => '2026-08-19',
            'direction' => 'IN',
            'amount' => '2600.00',
            'history' => 'PIX recebido (corrigido)',
        ], $this->operador->id);

        $this->assertSame('2600.00', (string) $atualizado->amount);
    }

    public function test_movimento_de_conciliacao_fechada_nao_pode_ser_alterado(): void
    {
        $movimento = $this->movimento('IN', '2500.00', '2026-08-19', 'PIX recebido');
        $statement = app(PeriodStatementService::class)
            ->create($this->contaId, '2026-08-01', '2026-08-31', 0, $this->operador->id);
        app(PeriodStatementService::class)->close($statement, $this->operador->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/FECHADA/');

        app(ManualMovementService::class)->update($movimento->fresh(), [
            'account_id' => $this->contaId,
            'movement_date' => '2026-08-19',
            'direction' => 'IN',
            'amount' => '99.00',
            'history' => 'tentativa',
        ], $this->operador->id);
    }

    public function test_movimento_pode_ser_excluido_enquanto_a_conciliacao_esta_aberta(): void
    {
        $movimento = $this->movimento('OUT', '80.00', '2026-08-19', 'Tarifa');
        app(PeriodStatementService::class)
            ->create($this->contaId, '2026-08-01', '2026-08-31', 0, $this->operador->id);

        app(ManualMovementService::class)->delete($movimento->fresh(), $this->operador->id);

        $this->assertNotNull($movimento->fresh()->deleted_at);
    }

    public function test_movimento_de_conciliacao_fechada_nao_pode_ser_excluido(): void
    {
        $movimento = $this->movimento('OUT', '80.00', '2026-08-19', 'Tarifa');
        $statement = app(PeriodStatementService::class)
            ->create($this->contaId, '2026-08-01', '2026-08-31', 0, $this->operador->id);
        app(PeriodStatementService::class)->close($statement, $this->operador->id);

        $this->expectException(DomainException::class);

        app(ManualMovementService::class)->delete($movimento->fresh(), $this->operador->id);
    }

    public function test_valor_zero_ou_negativo_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('O valor do movimento precisa ser maior que zero.');

        $this->movimento('IN', '0.00', '2026-08-19');
    }

    public function test_tela_aceita_valor_no_formato_brasileiro(): void
    {
        $resposta = $this->actingAs($this->operador)->post(route('manual-movements.store'), [
            'account_id' => $this->contaId,
            'movement_date' => '2026-08-19',
            'direction' => 'IN',
            'amount' => '2.500,00',
            'history' => 'PIX recebido - Cliente',
        ]);

        $resposta->assertRedirect();
        $this->assertSame('2500.00', (string) ManualMovement::query()->first()->amount);
    }

    public function test_lista_e_formulario_abrem_para_quem_pode_gerenciar(): void
    {
        $this->movimento('IN', '10.00', '2026-08-19', 'PIX');

        $this->actingAs($this->operador)->get(route('manual-movements.index'))
            ->assertOk()->assertSee('PIX');
        $this->actingAs($this->operador)->get(route('manual-movements.create'))
            ->assertOk()->assertSee('Entrada')->assertSee('Saída');
    }

    /**
     * A tela nunca imprime o valor cru do enum.
     */
    public function test_a_tela_mostra_entrada_e_saida_e_nunca_in_ou_out(): void
    {
        $this->movimento('OUT', '35.00', '2026-08-19', 'Tarifa de manutenção');

        $html = $this->actingAs($this->operador)->get(route('manual-movements.index'))->getContent();

        $this->assertStringNotContainsString('>OUT<', $html);
        $this->assertStringContainsString('Tarifa de manutenção', $html);
    }

    public function test_quem_so_visualiza_nao_pode_lancar(): void
    {
        config(['reconciliation.manage_user_ids' => []]);

        $this->actingAs($this->operador)->get(route('manual-movements.index'))->assertOk();
        $this->actingAs($this->operador)->get(route('manual-movements.create'))->assertForbidden();
        $this->actingAs($this->operador)->post(route('manual-movements.store'), [
            'account_id' => $this->contaId,
            'movement_date' => '2026-08-19',
            'direction' => 'IN',
            'amount' => '10,00',
            'history' => 'PIX',
        ])->assertForbidden();
    }
}
