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
use App\Models\PeriodStatement;
use App\Models\PeriodStatementLine;
use App\Models\SourceSystem;
use App\Models\TitleSettlement;
use App\Models\User;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Conciliação (Movimento do Período): criar ABERTA, atualizar enquanto o mês
 * acontece, fechar quando o período estiver conferido.
 *
 * A pergunta que originou esta tela foi "se eu apertar o botão, vai estragar
 * alguma coisa?". A resposta precisa ser garantida por teste, não por
 * promessa: criar/atualizar lê a base e grava o resumo, e não encosta em
 * título, liquidação, movimento manual ou status nenhum.
 */
class PeriodStatementTest extends TestCase
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

        $this->contaId = (int) Conta::query()->create(['nome' => 'Agro Colitti', 'banco' => 'Sicoob'])->id;

        $this->operador = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operador->id],
            'reconciliation.manage_user_ids' => [$this->operador->id],
            'gestao.legacy_ui' => false,
        ]);
    }

    private function titulo(FinancialTitleType $tipo, string $valor, string $externo, string $vencimento, string $parte, ?int $contaId = null): FinancialTitle
    {
        $codigo = $tipo === FinancialTitleType::Payable ? 'LEGACY_PAYABLE' : 'LEGACY_RECEIVABLE';
        SourceSystem::query()->firstOrCreate(['code' => $codigo], ['name' => 'Origem', 'active' => true]);

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: $codigo,
            externalId: $externo,
            type: $tipo,
            issueDate: '2025-12-01',
            dueDate: $vencimento,
            originalAmount: $valor,
            discountAmount: '0.00',
            additionAmount: '0.00',
            partyName: $parte,
            documentNumber: 'DOC-'.$externo,
            accountId: $contaId ?? $this->contaId,
            currency: 'BRL',
            installmentCount: 1,
        ))->title;
    }

    private function liquidar(FinancialTitle $titulo, string $valor, string $data): TitleSettlement
    {
        return app(SettlementService::class)->settle(
            titleId: $titulo->id,
            amount: $valor,
            settlementDate: $data,
            installmentId: $titulo->installments()->first()?->id,
            sourceSystemId: $titulo->source_system_id,
            externalId: 'liq-'.$titulo->external_id.'-'.$data,
        );
    }

    private function manual(string $direcao, string $valor, string $data, string $historico = 'Manual', ?int $contaId = null): ManualMovement
    {
        return app(ManualMovementService::class)->create([
            'account_id' => $contaId ?? $this->contaId,
            'movement_date' => $data,
            'direction' => $direcao,
            'amount' => $valor,
            'history' => $historico,
        ], $this->operador->id);
    }

    private function service(): PeriodStatementService
    {
        return app(PeriodStatementService::class);
    }

    private function criar(string $from, string $to, int $openingCents, ?int $contaId = null): PeriodStatement
    {
        return $this->service()->create($contaId ?? $this->contaId, $from, $to, $openingCents, $this->operador->id);
    }

    // ---------------------------------------------------------------------
    // Cálculo (preview/create): base já coberta e ainda válida sob o ciclo
    // de vida novo — criar não muda a matemática, só passa a exigir estado.
    // ---------------------------------------------------------------------

    public function test_saldo_corrido_soma_entrada_e_desconta_saida(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '7000.00', '1', '2026-04-30', 'Brendon'), '7000.00', '2026-01-08');
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '2000.00', '2', '2026-01-15', 'Fornecedor X'), '2000.00', '2026-01-15');

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 100000); // saldo inicial R$ 1.000,00

        $this->assertCount(2, $previa['lines']);

        $this->assertSame(700000, $previa['lines'][0]['amount_in_cents']);
        $this->assertNull($previa['lines'][0]['amount_out_cents']);
        $this->assertSame(800000, $previa['lines'][0]['running_balance_cents']);

        $this->assertNull($previa['lines'][1]['amount_in_cents']);
        $this->assertSame(200000, $previa['lines'][1]['amount_out_cents']);
        $this->assertSame(600000, $previa['lines'][1]['running_balance_cents']);

        $this->assertSame(600000, $previa['closing_cents']);
        $this->assertSame(700000, $previa['total_in_cents']);
        $this->assertSame(200000, $previa['total_out_cents']);
    }

    public function test_historico_traz_o_vencimento_com_v_e_o_nome_da_parte(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '500.00', '9', '2026-04-30', 'Brendon'), '500.00', '2026-01-08');

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31');

        $this->assertSame('V 30/04 - Brendon', $previa['lines'][0]['history']);
        $this->assertSame('2026-01-08', $previa['lines'][0]['movement_date']);
    }

    public function test_recorte_segue_a_data_do_pagamento_e_nao_o_vencimento(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '100.00', '10', '2026-01-20', 'Fora'), '100.00', '2026-02-03');
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '300.00', '11', '2025-12-20', 'Dentro'), '300.00', '2026-01-05');
        $this->titulo(FinancialTitleType::Payable, '900.00', '12', '2026-01-10', 'Nao pago');

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31');

        $this->assertCount(1, $previa['lines']);
        $this->assertSame('V 20/12 - Dentro', $previa['lines'][0]['history']);
    }

    public function test_titulo_de_outra_conta_nao_entra(): void
    {
        $outra = (int) Conta::query()->create(['nome' => 'Equus'])->id;

        $t = $this->titulo(FinancialTitleType::Payable, '400.00', '20', '2026-01-10', 'Outra conta');
        $t->update(['account_id' => $outra]);
        $this->liquidar($t, '400.00', '2026-01-10');

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31');

        $this->assertCount(0, $previa['lines']);
    }

    // ---------------------------------------------------------------------
    // 1) Criação — saldo inicial obrigatório
    // ---------------------------------------------------------------------

    public function test_criacao_pela_tela_exige_saldo_inicial_preenchido(): void
    {
        $this->actingAs($this->operador)
            ->post(route('period-statements.store'), [
                'account_id' => $this->contaId,
                'from' => '2026-01-01',
                'to' => '2026-01-31',
                // 'opening' ausente de propósito
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('opening');

        $this->assertSame(0, PeriodStatement::query()->count());
    }

    public function test_criacao_pela_tela_recusa_saldo_inicial_vazio(): void
    {
        $this->actingAs($this->operador)
            ->post(route('period-statements.store'), [
                'account_id' => $this->contaId, 'from' => '2026-01-01', 'to' => '2026-01-31', 'opening' => '   ',
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, PeriodStatement::query()->count());
    }

    public function test_criacao_pela_tela_aceita_saldo_inicial_zero_explicito(): void
    {
        // Zero digitado de propósito é diferente de "não informou" — precisa
        // ser aceito, não recusado.
        $this->actingAs($this->operador)
            ->post(route('period-statements.store'), [
                'account_id' => $this->contaId, 'from' => '2026-01-01', 'to' => '2026-01-31', 'opening' => '0,00',
            ])
            ->assertRedirect();

        $statement = PeriodStatement::query()->latest('id')->first();
        $this->assertNotNull($statement);
        $this->assertSame(0, $statement->opening_balance_cents);
    }

    // ---------------------------------------------------------------------
    // Criação — abre ABERTA, com os movimentos já existentes carregados
    // ---------------------------------------------------------------------

    public function test_criar_abre_em_estado_aberto(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 100000);

        $this->assertTrue($statement->isOpen());
        $this->assertSame('OPEN', $statement->status->value);
        $this->assertNotNull($statement->last_synced_at);
        $this->assertNull($statement->closed_at);
    }

    public function test_criar_carrega_movimentos_ja_existentes_no_periodo(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '7000.00', '30', '2026-01-08', 'Cliente'), '7000.00', '2026-01-08');
        $this->manual('OUT', '35.00', '2026-01-09', 'Tarifa');

        $statement = $this->criar('2026-01-01', '2026-01-31', 0);

        $this->assertSame(2, $statement->line_count);
        $this->assertSame(696500, $statement->closing_balance_cents);
    }

    public function test_criar_nao_altera_titulo_liquidacao_nem_movimento_manual(): void
    {
        $recebimento = $this->titulo(FinancialTitleType::Receivable, '1000.00', '31', '2026-01-10', 'Cliente');
        $this->liquidar($recebimento, '1000.00', '2026-01-10');
        $aberto = $this->titulo(FinancialTitleType::Payable, '250.00', '32', '2026-01-20', 'Fornecedor');
        $manual = $this->manual('OUT', '80.00', '2026-01-12', 'Tarifa');

        $antes = [
            'titulos' => DB::table('financial_titles')->orderBy('id')->get()->toJson(),
            'liquidacoes' => DB::table('title_settlements')->orderBy('id')->get()->toJson(),
            'manuais' => DB::table('manual_movements')->orderBy('id')->get()->toJson(),
        ];

        $this->criar('2026-01-01', '2026-01-31', 0);

        $this->assertSame($antes['titulos'], DB::table('financial_titles')->orderBy('id')->get()->toJson());
        $this->assertSame($antes['liquidacoes'], DB::table('title_settlements')->orderBy('id')->get()->toJson());
        $this->assertSame($antes['manuais'], DB::table('manual_movements')->orderBy('id')->get()->toJson());
        $this->assertSame('SETTLED', $recebimento->fresh()->status->value);
        $this->assertSame('OPEN', $aberto->fresh()->status->value);
        $this->assertNull($manual->fresh()->deleted_at);
    }

    public function test_nao_permite_duas_conciliacoes_abertas_com_periodo_sobreposto(): void
    {
        $this->criar('2026-01-01', '2026-01-31', 0);

        $this->expectException(DomainException::class);
        $this->criar('2026-01-15', '2026-02-15', 0);
    }

    public function test_permite_conciliacao_nova_apos_a_primeira_ser_fechada(): void
    {
        $primeira = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($primeira, $this->operador->id);

        $segunda = $this->criar('2026-01-15', '2026-02-15', 0);
        $this->assertTrue($segunda->isOpen());
    }

    // ---------------------------------------------------------------------
    // 6) Ordenação determinística — data, depois registro real no Gestão
    // ---------------------------------------------------------------------

    public function test_duas_linhas_no_mesmo_dia_ordenam_pelo_registro_no_gestao(): void
    {
        // A liquidação de "Segunda" é gravada DEPOIS da de "Primeira", mesmo
        // com a mesma data financeira — created_at é que decide a ordem.
        $t1 = $this->titulo(FinancialTitleType::Receivable, '100.00', '40', '2026-01-15', 'Primeira');
        $this->liquidar($t1, '100.00', '2026-01-10');

        usleep(1_100_000); // garante segundo diferente mesmo em bancos sem microssegundos

        $t2 = $this->titulo(FinancialTitleType::Receivable, '200.00', '41', '2026-01-16', 'Segunda');
        $this->liquidar($t2, '200.00', '2026-01-10');

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31');

        $this->assertCount(2, $previa['lines']);
        $this->assertSame('V 15/01 - Primeira', $previa['lines'][0]['history']);
        $this->assertSame('V 16/01 - Segunda', $previa['lines'][1]['history']);
    }

    public function test_ordem_e_a_mesma_em_chamadas_repetidas(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '10.00', '42', '2026-01-05', 'A'), '10.00', '2026-01-05');
        $this->manual('OUT', '20.00', '2026-01-05', 'B');
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '30.00', '43', '2026-01-05', 'C'), '30.00', '2026-01-05');

        $p1 = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31');
        $p2 = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31');

        $this->assertSame(array_column($p1['lines'], 'history'), array_column($p2['lines'], 'history'));
    }

    // ---------------------------------------------------------------------
    // 7-10) Atualizar: novos movimentos, idempotência, feedback
    // ---------------------------------------------------------------------

    public function test_atualizar_sem_movimento_novo_nao_muda_nada(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '1000.00', '50', '2026-01-10', 'Cliente'), '1000.00', '2026-01-10');
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $saldoAntes = $statement->closing_balance_cents;

        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(0, $resultado->novos);
        $this->assertSame(0, $resultado->atualizados);
        $this->assertSame(0, $resultado->removidos);
        $this->assertFalse($resultado->mudouAlgo());
        $this->assertSame($saldoAntes, $resultado->statement->closing_balance_cents);
    }

    public function test_atualizar_com_um_movimento_novo_adiciona_e_recalcula(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '1000.00', '51', '2026-01-05', 'Cliente'), '1000.00', '2026-01-05');
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->assertSame(1, $statement->line_count);

        $this->liquidar($this->titulo(FinancialTitleType::Payable, '400.00', '52', '2026-01-20', 'Fornecedor'), '400.00', '2026-01-20');

        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(1, $resultado->novos);
        $this->assertSame(0, $resultado->atualizados);
        $this->assertSame(0, $resultado->removidos);
        $this->assertSame(2, $resultado->statement->line_count);
        $this->assertSame(60000, $resultado->statement->closing_balance_cents);
    }

    public function test_atualizar_com_varios_movimentos_novos(): void
    {
        // O exemplo do pedido: conciliação criada com 20 movimentos e
        // R$ 80.000; surgem 3 novos (+7.000, -2.500, -800 manual); esperado
        // 23 movimentos e R$ 83.700.
        for ($i = 1; $i <= 20; $i++) {
            $this->liquidar(
                $this->titulo(FinancialTitleType::Receivable, '4000.00', 'g'.$i, '2026-01-05', 'Cliente '.$i),
                '4000.00', '2026-01-05',
            );
        }
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->assertSame(20, $statement->line_count);
        $this->assertSame(8000000, $statement->closing_balance_cents); // R$ 80.000,00

        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '7000.00', 'g21', '2026-01-06', 'Novo A'), '7000.00', '2026-01-08');
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '2500.00', 'g22', '2026-01-06', 'Novo B'), '2500.00', '2026-01-08');
        $this->manual('OUT', '800.00', '2026-01-08', 'Novo C manual');

        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(3, $resultado->novos);
        $this->assertSame(23, $resultado->statement->line_count);
        $this->assertSame(8370000, $resultado->statement->closing_balance_cents); // R$ 83.700,00
    }

    public function test_atualizar_tres_vezes_seguidas_sem_novidade_e_idempotente(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '500.00', '60', '2026-01-10', 'Cliente'), '500.00', '2026-01-10');
        $statement = $this->criar('2026-01-01', '2026-01-31', 100000);

        $r1 = $this->service()->refresh($statement, $this->operador->id);
        $r2 = $this->service()->refresh($statement, $this->operador->id);
        $r3 = $this->service()->refresh($statement, $this->operador->id);

        foreach ([$r1, $r2, $r3] as $r) {
            $this->assertSame(0, $r->novos);
            $this->assertSame(0, $r->removidos);
        }

        $this->assertSame(1, $statement->fresh()->line_count, '0 duplicidades');
        $this->assertSame($r1->statement->closing_balance_cents, $r3->statement->closing_balance_cents, 'mesmo saldo final');
    }

    public function test_atualizar_nao_duplica_ao_rodar_varias_vezes_com_movimentos_novos_intercalados(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);

        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '61', '2026-01-05', 'A'), '100.00', '2026-01-05');
        $this->service()->refresh($statement, $this->operador->id);
        $this->service()->refresh($statement, $this->operador->id); // idempotente no meio

        $this->liquidar($this->titulo(FinancialTitleType::Payable, '30.00', '62', '2026-01-06', 'B'), '30.00', '2026-01-06');
        $this->service()->refresh($statement, $this->operador->id);
        $this->service()->refresh($statement, $this->operador->id);
        $this->service()->refresh($statement, $this->operador->id);

        $statement->refresh();
        $this->assertSame(2, $statement->line_count);
        $this->assertSame(7000, $statement->closing_balance_cents);

        $ids = $statement->lines()->pluck('title_settlement_id')->filter()->all();
        $this->assertSame(count($ids), count(array_unique($ids)), '0 duplicidades');
    }

    public function test_atualizar_pela_tela_mostra_contagem_de_novos_e_ultima_atualizacao(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '250.00', '63', '2026-01-05', 'Cliente'), '250.00', '2026-01-05');

        $this->actingAs($this->operador)
            ->post(route('period-statements.refresh', $statement))
            ->assertRedirect();

        $statement->refresh();
        $this->assertNotNull($statement->last_synced_at);

        $html = $this->actingAs($this->operador)->get(route('period-statements.show', $statement))->getContent();
        $this->assertStringContainsString($statement->last_synced_at->format('d/m/Y H:i'), $html);
    }

    public function test_atualizar_pela_tela_sem_novidade_avisa_que_ja_esta_atualizada(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);

        $this->actingAs($this->operador)
            ->post(route('period-statements.refresh', $statement))
            ->assertRedirect()
            ->assertSessionHas('success', 'A conciliação já está atualizada.');
    }

    // ---------------------------------------------------------------------
    // 12) Movimento manual criado depois entra ao atualizar
    // ---------------------------------------------------------------------

    public function test_movimento_manual_criado_depois_entra_ao_atualizar(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 100000);
        $this->assertSame(0, $statement->line_count);

        $this->manual('IN', '2500.00', '2026-01-19', 'PIX recebido');

        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(1, $resultado->novos);
        $this->assertSame(1, $resultado->statement->line_count);
        $this->assertSame(350000, $resultado->statement->closing_balance_cents);
    }

    // ---------------------------------------------------------------------
    // 10/4) Conta correta — nunca mistura, nunca inventa
    // ---------------------------------------------------------------------

    public function test_movimento_de_outra_conta_nao_entra_ao_atualizar(): void
    {
        $outra = (int) Conta::query()->create(['nome' => 'Outra conta'])->id;
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);

        $this->manual('IN', '999.00', '2026-01-10', 'Não é desta conta', $outra);
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '888.00', '70', '2026-01-10', 'Outra', $outra), '888.00', '2026-01-10');

        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(0, $resultado->novos);
        $this->assertSame(0, $resultado->statement->line_count);
    }

    public function test_movimento_fora_do_periodo_nao_entra(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);

        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '71', '2026-02-05', 'Fevereiro'), '100.00', '2026-02-05');
        $this->manual('IN', '50.00', '2025-12-20', 'Dezembro');

        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(0, $resultado->novos);
    }

    public function test_titulo_sem_conta_e_contado_e_nao_incluido_em_nenhuma_conciliacao(): void
    {
        $codigo = 'LEGACY_RECEIVABLE';
        SourceSystem::query()->firstOrCreate(['code' => $codigo], ['name' => 'Origem', 'active' => true]);
        $semConta = app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: $codigo, externalId: '80', type: FinancialTitleType::Receivable,
            issueDate: '2025-12-01', dueDate: '2026-01-15', originalAmount: '300.00',
            discountAmount: '0.00', additionAmount: '0.00', partyName: 'Sem conta',
            documentNumber: 'DOC-80', accountId: null, currency: 'BRL', installmentCount: 1,
        ))->title;
        $this->liquidar($semConta, '300.00', '2026-01-10');

        $this->assertSame(1, $this->service()->contarSemConta('2026-01-01', '2026-01-31'));

        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->assertSame(0, $statement->line_count, 'não inventou conta para o título');
    }

    // ---------------------------------------------------------------------
    // 13/15) Movimento alterado enquanto ABERTA
    // ---------------------------------------------------------------------

    public function test_movimento_manual_corrigido_em_conciliacao_aberta_e_permitido_e_atualizar_reflete(): void
    {
        $manual = $this->manual('IN', '2500.00', '2026-01-19', 'PIX recebido');
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->assertSame(250000, $statement->closing_balance_cents);

        // Corrigir enquanto ABERTA não pode ser bloqueado.
        app(ManualMovementService::class)->update($manual, [
            'account_id' => $this->contaId, 'movement_date' => '2026-01-19',
            'direction' => 'IN', 'amount' => '2600.00', 'history' => 'PIX recebido (corrigido)',
        ], $this->operador->id);

        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(0, $resultado->novos);
        $this->assertSame(1, $resultado->atualizados);
        $this->assertSame(0, $resultado->removidos);
        $this->assertSame(260000, $resultado->statement->closing_balance_cents);
        $this->assertSame('PIX recebido (corrigido)', $resultado->statement->lines->first()->history);
    }

    // ---------------------------------------------------------------------
    // 16) Movimento removido/cancelado enquanto ABERTA
    // ---------------------------------------------------------------------

    public function test_movimento_manual_excluido_em_conciliacao_aberta_sai_sem_deixar_valor_fantasma(): void
    {
        $manual = $this->manual('OUT', '80.00', '2026-01-19', 'Tarifa errada');
        $statement = $this->criar('2026-01-01', '2026-01-31', 100000);
        $this->assertSame(1, $statement->line_count);
        $this->assertSame(92000, $statement->closing_balance_cents);

        app(ManualMovementService::class)->delete($manual, $this->operador->id);

        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(0, $resultado->novos);
        $this->assertSame(0, $resultado->atualizados);
        $this->assertSame(1, $resultado->removidos);
        $this->assertSame(0, $resultado->statement->line_count);
        $this->assertSame(100000, $resultado->statement->closing_balance_cents, 'sem valor fantasma');
    }

    // ---------------------------------------------------------------------
    // 13/14) Fechamento: conciliação fechada não atualiza; movimento
    // protegido continua protegido
    // ---------------------------------------------------------------------

    public function test_conciliacao_fechada_recusa_atualizar(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($statement, $this->operador->id);

        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '999.00', '90', '2026-01-05', 'Depois de fechar'), '999.00', '2026-01-05');

        $this->expectException(DomainException::class);
        $this->service()->refresh($statement, $this->operador->id);
    }

    public function test_conciliacao_fechada_recusa_atualizar_pela_tela(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($statement, $this->operador->id);

        $this->actingAs($this->operador)
            ->post(route('period-statements.refresh', $statement))
            ->assertRedirect()
            ->assertSessionHasErrors();
    }

    public function test_fechar_atualiza_uma_ultima_vez_antes_de_travar(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '500.00', '91', '2026-01-05', 'Última hora'), '500.00', '2026-01-05');

        $fechado = $this->service()->close($statement, $this->operador->id);

        $this->assertSame(1, $fechado->line_count, 'fechar deveria ter atualizado antes de travar');
        $this->assertSame(50000, $fechado->closing_balance_cents);
    }

    public function test_movimento_manual_de_conciliacao_fechada_nao_pode_ser_corrigido(): void
    {
        $manual = $this->manual('OUT', '80.00', '2026-01-19', 'Tarifa');
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($statement, $this->operador->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/FECHADA/');

        app(ManualMovementService::class)->update($manual->fresh(), [
            'account_id' => $this->contaId, 'movement_date' => '2026-01-19',
            'direction' => 'OUT', 'amount' => '99.00', 'history' => 'tentativa',
        ], $this->operador->id);
    }

    public function test_movimento_manual_de_conciliacao_fechada_nao_pode_ser_excluido(): void
    {
        $manual = $this->manual('OUT', '80.00', '2026-01-19', 'Tarifa');
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($statement, $this->operador->id);

        $this->expectException(DomainException::class);

        app(ManualMovementService::class)->delete($manual->fresh(), $this->operador->id);
    }

    public function test_movimento_manual_de_conciliacao_aberta_pode_ser_corrigido_e_excluido(): void
    {
        $manual = $this->manual('OUT', '80.00', '2026-01-19', 'Tarifa');
        $this->criar('2026-01-01', '2026-01-31', 0); // permanece ABERTA

        app(ManualMovementService::class)->update($manual, [
            'account_id' => $this->contaId, 'movement_date' => '2026-01-19',
            'direction' => 'OUT', 'amount' => '90.00', 'history' => 'Tarifa corrigida',
        ], $this->operador->id);
        app(ManualMovementService::class)->delete($manual->fresh(), $this->operador->id);

        $this->assertNotNull($manual->fresh()->deleted_at);
    }

    public function test_fechar_pela_tela_esconde_botao_atualizar(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($statement, $this->operador->id);

        $html = $this->actingAs($this->operador)->get(route('period-statements.show', $statement))->getContent();

        $this->assertStringNotContainsString('Atualizar conciliação', $html);
        $this->assertStringContainsString('Fechada', $html);
    }

    public function test_fechar_preserva_snapshot(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '7000.00', '92', '2026-01-08', 'Cliente'), '7000.00', '2026-01-08');
        $statement = $this->criar('2026-01-01', '2026-01-31', 100000);
        $fechado = $this->service()->close($statement, $this->operador->id);

        $snapshotAntes = $fechado->fresh('lines')->toArray();

        // Uma liquidação nova no mesmo período NÃO pode alterar o que já foi
        // fechado, mesmo que alguém tentasse via refresh (que já recusa) —
        // aqui a prova é que o registro gravado simplesmente não muda sozinho.
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '1.00', '93', '2026-01-09', 'Depois'), '1.00', '2026-01-09');

        $snapshotDepois = PeriodStatement::query()->with('lines')->find($fechado->id)->toArray();

        $this->assertSame($snapshotAntes['closing_balance_cents'], $snapshotDepois['closing_balance_cents']);
        $this->assertSame($snapshotAntes['line_count'], $snapshotDepois['line_count']);
        $this->assertCount(1, $snapshotDepois['lines']);
    }

    public function test_fechar_registra_auditoria(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($statement, $this->operador->id);

        $evento = AuditEvent::query()->where('action', 'PERIOD_STATEMENT_CLOSED')->first();
        $this->assertNotNull($evento);
        $this->assertSame($this->operador->id, $evento->actor_id);
        $this->assertSame('CLOSED', $evento->after_state['status']);
    }

    public function test_fechar_de_novo_e_recusado(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($statement, $this->operador->id);

        $this->expectException(DomainException::class);
        $this->service()->close($statement->fresh(), $this->operador->id);
    }

    // ---------------------------------------------------------------------
    // 15) Excluir relatório continua coerente (não perde teste existente)
    // ---------------------------------------------------------------------

    public function test_excluir_relatorio_nao_toca_titulo_liquidacao_nem_movimento_manual(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Receivable, '1000.00', '80', '2026-01-10', 'Cliente');
        $this->liquidar($titulo, '1000.00', '2026-01-10');
        app(ManualMovementService::class)->create([
            'account_id' => $this->contaId,
            'movement_date' => '2026-01-15',
            'direction' => 'OUT',
            'amount' => '35.00',
            'history' => 'Tarifa',
        ], $this->operador->id);

        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->assertSame(2, $statement->line_count);

        $antesTitulo = DB::table('financial_titles')->orderBy('id')->get()->toJson();
        $antesLiquidacao = DB::table('title_settlements')->orderBy('id')->get()->toJson();
        $antesManual = DB::table('manual_movements')->orderBy('id')->get()->toJson();

        $this->service()->delete($statement, $this->operador->id);

        $this->assertSame($antesTitulo, DB::table('financial_titles')->orderBy('id')->get()->toJson(), 'algum título foi alterado');
        $this->assertSame($antesLiquidacao, DB::table('title_settlements')->orderBy('id')->get()->toJson(), 'alguma liquidação foi alterada');
        $this->assertSame($antesManual, DB::table('manual_movements')->orderBy('id')->get()->toJson(), 'algum movimento manual foi alterado');

        $this->assertSame('SETTLED', $titulo->fresh()->status->value);
        $this->assertSame(1, ManualMovement::query()->count());
    }

    public function test_excluir_relatorio_apaga_o_relatorio_e_suas_linhas(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '500.00', '81', '2026-01-10', 'Cliente'), '500.00', '2026-01-10');

        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $id = $statement->id;
        $this->assertSame(1, PeriodStatementLine::where('period_statement_id', $id)->count());

        $this->service()->delete($statement, $this->operador->id);

        $this->assertNull(PeriodStatement::find($id));
        $this->assertSame(0, PeriodStatementLine::where('period_statement_id', $id)->count());
    }

    public function test_excluir_relatorio_fechado_tambem_funciona_sem_tocar_dados(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Receivable, '500.00', '82', '2026-01-10', 'Cliente');
        $this->liquidar($titulo, '500.00', '2026-01-10');
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($statement, $this->operador->id);

        $this->service()->delete($statement->fresh(), $this->operador->id);

        $this->assertNull(PeriodStatement::find($statement->id));
        $this->assertSame('SETTLED', $titulo->fresh()->status->value);
    }

    public function test_criar_de_novo_apos_excluir_reproduz_o_mesmo_resultado(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '7000.00', '82', '2026-01-08', 'Brendon'), '7000.00', '2026-01-08');
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '2000.00', '83', '2026-01-15', 'Fornecedor'), '2000.00', '2026-01-15');
        $this->manual('OUT', '35.00', '2026-01-20', 'Tarifa');

        $primeiro = $this->criar('2026-01-01', '2026-01-31', 100000);
        $this->service()->delete($primeiro, $this->operador->id);
        $this->assertSame(0, PeriodStatement::query()->count());

        $segundo = $this->criar('2026-01-01', '2026-01-31', 100000);

        $this->assertSame($primeiro->closing_balance_cents, $segundo->closing_balance_cents);
        $this->assertSame($primeiro->total_in_cents, $segundo->total_in_cents);
        $this->assertSame($primeiro->total_out_cents, $segundo->total_out_cents);
        $this->assertSame($primeiro->line_count, $segundo->line_count);
    }

    public function test_excluir_relatorio_pela_tela_redireciona_e_confirma_intactos(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Receivable, '900.00', '85', '2026-01-10', 'Cliente');
        $this->liquidar($titulo, '900.00', '2026-01-10');
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);

        $this->actingAs($this->operador)
            ->delete(route('period-statements.destroy', $statement))
            ->assertRedirect(route('period-statements.index'));

        $this->assertNull(PeriodStatement::find($statement->id));
        $this->assertSame('SETTLED', $titulo->fresh()->status->value);
        $this->assertSame(1, TitleSettlement::query()->count());
    }

    public function test_quem_so_visualiza_nao_pode_excluir_relatorio(): void
    {
        config(['reconciliation.manage_user_ids' => []]);
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '300.00', '86', '2026-01-10', 'Cliente'), '300.00', '2026-01-10');
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);

        $this->actingAs($this->operador)
            ->delete(route('period-statements.destroy', $statement))
            ->assertForbidden();

        $this->assertNotNull(PeriodStatement::find($statement->id));
    }

    // ---------------------------------------------------------------------
    // 23) Invariante matemática: saldo = inicial + entradas - saídas
    // ---------------------------------------------------------------------

    public function test_saldo_fecha_matematicamente_na_criacao_e_apos_atualizar(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '7000.00', '95', '2026-01-08', 'A'), '7000.00', '2026-01-08');
        $this->manual('OUT', '150.00', '2026-01-09', 'Tarifa');

        $statement = $this->criar('2026-01-01', '2026-01-31', 100000);
        $this->assertSame(
            $statement->opening_balance_cents + $statement->total_in_cents - $statement->total_out_cents,
            $statement->closing_balance_cents,
        );

        $this->liquidar($this->titulo(FinancialTitleType::Payable, '2500.00', '96', '2026-01-20', 'B'), '2500.00', '2026-01-20');
        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(
            $resultado->statement->opening_balance_cents + $resultado->statement->total_in_cents - $resultado->statement->total_out_cents,
            $resultado->statement->closing_balance_cents,
        );
    }

    // ---------------------------------------------------------------------
    // Saldo inicial sugerido: só olha conciliação FECHADA
    // ---------------------------------------------------------------------

    public function test_saldo_inicial_sugerido_vem_do_periodo_anterior_fechado(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '1500.00', '50', '2025-12-10', 'Dezembro'), '1500.00', '2025-12-10');

        $dezembro = $this->criar('2025-12-01', '2025-12-31', 0);
        $this->service()->close($dezembro, $this->operador->id);

        $sugerido = $this->service()->suggestedOpeningCents($this->contaId, '2026-01-01');

        $this->assertSame(150000, $sugerido);
    }

    public function test_saldo_inicial_sugerido_e_nulo_sem_periodo_fechado_anterior(): void
    {
        $this->assertNull($this->service()->suggestedOpeningCents($this->contaId, '2026-01-01'));
    }

    public function test_saldo_inicial_sugerido_ignora_periodo_ainda_aberto(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '1500.00', '51', '2025-12-10', 'Dezembro'), '1500.00', '2025-12-10');
        $this->criar('2025-12-01', '2025-12-31', 0); // fica ABERTA

        $this->assertNull($this->service()->suggestedOpeningCents($this->contaId, '2026-01-01'));
    }

    // ---------------------------------------------------------------------
    // Tela
    // ---------------------------------------------------------------------

    public function test_tela_mostra_cabecalho_saldo_e_estado(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '7000.00', '60', '2026-04-30', 'Brendon'), '7000.00', '2026-01-08');

        $statement = $this->criar('2026-01-01', '2026-01-31', 100000);

        $this->actingAs($this->operador)
            ->get(route('period-statements.show', $statement))
            ->assertOk()
            ->assertSee('Sicoob')
            ->assertSee('Agro Colitti')
            ->assertSee('Em andamento')
            ->assertSee('Saldo atual')
            ->assertSee('Saldo referente ao dia 31/12/2025')
            ->assertSee('N° DOC', false)
            ->assertSee('HISTÓRICO', false)
            ->assertSee('V 30/04 - Brendon')
            ->assertSee('R$ 8.000,00');
    }

    public function test_tela_de_fechada_mostra_saldo_final_em_vez_de_atual(): void
    {
        $statement = $this->criar('2026-01-01', '2026-01-31', 0);
        $this->service()->close($statement, $this->operador->id);

        $html = $this->actingAs($this->operador)->get(route('period-statements.show', $statement))->getContent();

        $this->assertStringContainsString('Saldo final', $html);
        $this->assertStringNotContainsString('Saldo atual', $html);
    }

    public function test_tela_de_criar_abre_sem_quebrar_quando_nao_ha_sugestao_de_saldo(): void
    {
        // O link do Dashboard chega com from/to e sem opening nem account_id —
        // exatamente o caso em que suggestedOpeningCents() devolve null.
        $this->actingAs($this->operador)
            ->get(route('period-statements.create', ['from' => '2026-01-01', 'to' => '2026-01-31']))
            ->assertOk()
            ->assertSee('Obrigatório', false)
            ->assertDontSee('Confirmar');
    }

    public function test_criar_pela_tela_grava_e_redireciona(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '200.00', '70', '2026-01-10', 'Fornecedor'), '200.00', '2026-01-10');

        $this->actingAs($this->operador)
            ->post(route('period-statements.store'), [
                'account_id' => $this->contaId,
                'from' => '2026-01-01',
                'to' => '2026-01-31',
                'opening' => '1.000,00',
            ])
            ->assertRedirect();

        $statement = PeriodStatement::query()->latest('id')->first();

        $this->assertNotNull($statement);
        $this->assertSame(100000, $statement->opening_balance_cents);
        $this->assertSame(80000, $statement->closing_balance_cents);
        $this->assertSame('Sicoob', $statement->account_bank);
        $this->assertTrue($statement->isOpen());
    }
}
