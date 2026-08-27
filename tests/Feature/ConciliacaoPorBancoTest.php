<?php

namespace Tests\Feature;

use App\Application\Financial\ManualMovementService;
use App\Application\Financial\PeriodStatementService;
use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\PeriodStatementSection;
use App\Domain\Financial\TitleIngestionData;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\FinancialTitle;
use App\Models\PeriodStatement;
use App\Models\SourceSystem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Conciliação por conta bancária, e o bloco de "em aberto" que fecha o
 * relatório — as duas coisas vindas das planilhas de conciliação do Itaú.
 *
 * O cabeçalho de cada planilha tem duas linhas, e elas são conceitos
 * diferentes: a EMPRESA ("Acop Files") e a CONTA BANCÁRIA ("Banco Itaú -
 * Agência 6260 - C/C 13377-9"). Quem tem extrato e saldo é a segunda.
 *
 * O detalhe que decide o desenho: `contas` e `contasareceber` NÃO guardam
 * banco — conferido coluna a coluna no `information_schema` das duas. Então a
 * ligação entre uma liquidação e um banco é só a convenção declarada no
 * cadastro (a conta padrão da empresa), e o teste existe para travar o que o
 * sistema faz quando essa convenção não se aplica: não chutar.
 */
class ConciliacaoPorBancoTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private int $contaId;

    private BankAccount $itau;

    private ?BankAccount $bb = null;

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

        // A Acop Files opera por UMA conta — o Itaú do cabeçalho da planilha.
        // É a regra confirmada com a administração (ADR-018), e é por isso que
        // ela é o padrão do setUp: o segundo banco é a exceção, e quem precisa
        // dele chama `segundoBanco()`.
        $this->itau = BankAccount::query()->create([
            'company_id' => $this->contaId, 'company_name' => 'Acop Files',
            'bank_name' => 'Banco Itaú', 'bank_code' => '341',
            'agency' => '6260', 'number' => '13377-9',
            'active' => true, 'is_default' => true,
        ]);

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

    private function titulo(
        FinancialTitleType $tipo,
        string $valor,
        string $externo,
        string $vencimento,
        string $parte,
        string $emissao = '2026-01-05',
    ): FinancialTitle {
        $codigo = $tipo === FinancialTitleType::Payable ? 'LEGACY_PAYABLE' : 'LEGACY_RECEIVABLE';
        SourceSystem::query()->firstOrCreate(['code' => $codigo], ['name' => 'Origem', 'active' => true]);

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: $codigo,
            externalId: $externo,
            type: $tipo,
            issueDate: $emissao,
            dueDate: $vencimento,
            originalAmount: $valor,
            discountAmount: '0.00',
            additionAmount: '0.00',
            partyName: $parte,
            documentNumber: 'NF.'.$externo,
            accountId: $this->contaId,
            currency: 'BRL',
            installmentCount: 1,
        ))->title;
    }

    private function liquidar(FinancialTitle $titulo, string $valor, string $data): void
    {
        app(SettlementService::class)->settle(
            titleId: $titulo->id,
            amount: $valor,
            settlementDate: $data,
            installmentId: $titulo->installments()->first()?->id,
            sourceSystemId: $titulo->source_system_id,
            externalId: 'liq-'.$titulo->external_id,
        );
    }

    /**
     * Um SEGUNDO banco para a mesma empresa — o "Depositou BB 30/06/2026" que
     * aparece no histórico das planilhas.
     *
     * Criar isto muda o comportamento do sistema de propósito: com duas contas
     * ativas a dedução de conta deixa de valer, e o que não tem banco definido
     * para de entrar em qualquer conciliação. Por isso não fica no setUp.
     */
    private function segundoBanco(): BankAccount
    {
        return $this->bb ??= BankAccount::query()->create([
            'company_id' => $this->contaId, 'company_name' => 'Acop Files',
            'bank_name' => 'Banco do Brasil', 'bank_code' => '001',
            'agency' => '0929', 'number' => '53120-0',
            'active' => true, 'is_default' => false,
        ]);
    }

    private function service(): PeriodStatementService
    {
        return app(PeriodStatementService::class);
    }

    // ---------------------------------------------------------------------
    // Bloco "em aberto" — o rodapé da planilha
    // ---------------------------------------------------------------------

    public function test_titulo_nao_baixado_aparece_no_bloco_em_aberto(): void
    {
        $this->titulo(FinancialTitleType::Receivable, '1500.00', 'R1', '2026-02-10', 'Sirio Libanes');

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->itau->id);

        $this->assertCount(0, $previa['lines'], 'não houve baixa, então não há movimento');
        $this->assertCount(1, $previa['pending']);
        $this->assertSame('NF.R1', $previa['pending'][0]['document_number']);
        $this->assertSame(150000, $previa['pending'][0]['amount_in_cents']);
        $this->assertNull($previa['pending'][0]['amount_out_cents']);
    }

    /**
     * O que a planilha faz e o relatório precisa repetir: a pendência fica
     * ABAIXO do saldo final e não interfere nele. Somar previsão a extrato é
     * exatamente o erro que a conciliação existe para evitar.
     */
    public function test_pendencia_nao_entra_no_saldo_nem_nos_totais(): void
    {
        $pago = $this->titulo(FinancialTitleType::Payable, '100.00', 'P1', '2026-01-10', 'Fornecedor');
        $this->liquidar($pago, '100.00', '2026-01-10');
        $this->titulo(FinancialTitleType::Receivable, '9999.00', 'R9', '2026-03-01', 'Cliente futuro');

        $statement = $this->service()->create(
            $this->contaId, '2026-01-01', '2026-01-31', 50000, $this->operador->id, $this->itau->id,
        );

        $this->assertSame(40000, $statement->closing_balance_cents, '50.000 - 10.000 da baixa');
        $this->assertSame(0, $statement->total_in_cents, 'a pendência de 9.999 não é entrada');
        $this->assertSame(10000, $statement->total_out_cents);
        $this->assertSame(1, $statement->line_count, 'line_count conta só movimento');

        $pendentes = $statement->lines()->where('section', PeriodStatementSection::Pending->value)->get();
        $this->assertCount(1, $pendentes);
        $this->assertSame(0, (int) $pendentes->first()->running_balance_cents, 'pendência não tem saldo corrido');
    }

    public function test_baixa_registrada_tira_o_titulo_do_bloco_em_aberto(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Receivable, '700.00', 'R2', '2026-02-20', 'Cliente');
        $statement = $this->service()->create(
            $this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id, $this->itau->id,
        );

        $this->assertCount(1, $statement->lines()->where('section', 'PENDING')->get());

        // Baixa FORA do período: não cria linha de movimento nenhuma aqui, mas
        // o título deixa de estar em aberto. Sem reconstruir o bloco, ele
        // ficaria pendurado no rodapé para sempre.
        $this->liquidar($titulo, '700.00', '2026-02-20');
        $this->service()->refresh($statement->fresh(), $this->operador->id);

        $this->assertCount(0, $statement->fresh()->lines()->where('section', 'PENDING')->get());
    }

    public function test_baixa_parcial_deixa_no_bloco_so_o_que_falta(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Payable, '1000.00', 'P2', '2026-02-15', 'Fornecedor');
        $this->liquidar($titulo, '400.00', '2026-01-20');

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->itau->id);

        $this->assertCount(1, $previa['pending']);
        $this->assertSame(60000, $previa['pending'][0]['amount_out_cents'], 'pende só o saldo de 600');
    }

    /**
     * Um título emitido depois do fim do período ainda não era pendência
     * conhecida naquele mês — não pode aparecer no rodapé dele.
     */
    public function test_titulo_emitido_depois_do_periodo_nao_entra(): void
    {
        $this->titulo(FinancialTitleType::Receivable, '500.00', 'R3', '2026-04-01', 'Cliente', emissao: '2026-03-15');

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->itau->id);

        $this->assertCount(0, $previa['pending']);
    }

    // ---------------------------------------------------------------------
    // Recorte por conta bancária
    // ---------------------------------------------------------------------

    /**
     * A dedução em ação: a origem não grava banco, mas a empresa só tem uma
     * conta, então não há o que escolher — a liquidação nasce apontando para
     * ela e entra na conciliação daquele banco.
     */
    public function test_liquidacao_da_origem_recebe_a_conta_unica_da_empresa(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Payable, '250.00', 'P3', '2026-01-15', 'Fornecedor');
        $this->liquidar($titulo, '250.00', '2026-01-15');

        $this->assertSame(
            $this->itau->id,
            (int) $titulo->settlements()->first()->bank_account_id,
            'com uma conta só, o banco da liquidação é dedutível',
        );

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->itau->id);
        $this->assertCount(1, $previa['lines']);
    }

    /**
     * O outro lado da mesma regra, e é ele que impede a volta da convenção do
     * `is_default` que custou −R$ 1.805.279,37 em 2026 (ADR-017): com duas
     * contas ativas a premissa "uma empresa, uma conta" não vale, a informação
     * falta de verdade, e o sistema não elege nenhuma.
     */
    public function test_com_duas_contas_a_liquidacao_da_origem_fica_sem_banco(): void
    {
        $bb = $this->segundoBanco();

        $titulo = $this->titulo(FinancialTitleType::Payable, '250.00', 'P3', '2026-01-15', 'Fornecedor');
        $this->liquidar($titulo, '250.00', '2026-01-15');

        $this->assertNull(
            $titulo->settlements()->first()->bank_account_id,
            'duas contas: não dá para deduzir, e chutar é o erro que se está encerrando',
        );

        $noItau = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->itau->id);
        $noBb = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $bb->id);

        $this->assertCount(0, $noItau['lines'], 'nem para a conta padrão — é isso que muda');
        $this->assertCount(0, $noBb['lines']);
        $this->assertCount(0, $noBb['pending'], 'a pendência segue a mesma regra');
    }

    /**
     * Não entrar em conciliação nenhuma só é aceitável se ficar VISÍVEL. O
     * contador é o que transforma a linha invisível em fila de trabalho.
     */
    public function test_a_liquidacao_sem_banco_e_contada_como_pendencia(): void
    {
        $bb = $this->segundoBanco();

        $titulo = $this->titulo(FinancialTitleType::Payable, '250.00', 'P4', '2026-01-15', 'Fornecedor');
        $this->liquidar($titulo, '250.00', '2026-01-15');

        $this->assertSame(1, $this->service()->contarSemContaBancaria(
            $this->contaId, '2026-01-01', '2026-01-31', $this->itau->id,
        ));
        $this->assertSame(1, $this->service()->contarSemContaBancaria(
            $this->contaId, '2026-01-01', '2026-01-31', $bb->id,
        ));
    }

    /**
     * Com uma conta só não há pendência a comunicar: a liquidação sem banco
     * entrou na conciliação normalmente. Avisar aqui seria alarme falso.
     */
    public function test_com_uma_conta_so_nao_ha_pendencia_de_banco(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Payable, '250.00', 'P5', '2026-01-15', 'Fornecedor');
        $this->liquidar($titulo, '250.00', '2026-01-15');

        $this->assertSame(0, $this->service()->contarSemContaBancaria(
            $this->contaId, '2026-01-01', '2026-01-31', $this->itau->id,
        ));
    }

    public function test_movimento_manual_vai_para_o_banco_que_foi_escolhido(): void
    {
        app(ManualMovementService::class)->create([
            'account_id' => $this->contaId,
            'bank_account_id' => $this->segundoBanco()->id,
            'movement_date' => '2026-01-08',
            'direction' => 'IN',
            'amount' => '3000.00',
            'history' => 'Depósito recebido no BB',
        ], $this->operador->id);

        $noBb = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->segundoBanco()->id);
        $noItau = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->itau->id);

        $this->assertCount(1, $noBb['lines']);
        $this->assertSame('Depósito recebido no BB', $noBb['lines'][0]['history']);
        $this->assertCount(0, $noItau['lines'], 'não vaza para a conta padrão');
    }

    /**
     * Sem banco preenchido o movimento é da conta única da empresa — o caso
     * normal, e o que o cabeçalho da planilha assume sem ninguém precisar dizer.
     */
    public function test_movimento_manual_sem_banco_cai_na_conta_unica_da_empresa(): void
    {
        app(ManualMovementService::class)->create([
            'account_id' => $this->contaId,
            'movement_date' => '2026-01-09',
            'direction' => 'OUT',
            'amount' => '99.50',
            'history' => 'Tar Cobrança EXP',
        ], $this->operador->id);

        $noItau = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->itau->id);

        $this->assertCount(1, $noItau['lines']);
    }

    public function test_com_duas_contas_o_movimento_manual_sem_banco_nao_entra_em_nenhuma(): void
    {
        $bb = $this->segundoBanco();

        app(ManualMovementService::class)->create([
            'account_id' => $this->contaId,
            'movement_date' => '2026-01-09',
            'direction' => 'OUT',
            'amount' => '99.50',
            'history' => 'Tar Cobrança EXP',
        ], $this->operador->id);

        $noItau = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->itau->id);
        $noBb = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $bb->id);

        $this->assertCount(0, $noItau['lines']);
        $this->assertCount(0, $noBb['lines']);
    }

    public function test_numero_do_documento_do_movimento_manual_chega_no_relatorio(): void
    {
        app(ManualMovementService::class)->create([
            'account_id' => $this->contaId,
            'document_number' => 'NF.291',
            'movement_date' => '2026-01-12',
            'direction' => 'IN',
            'amount' => '39043.70',
            'history' => 'Unicamp (Guarda de Documentos)',
        ], $this->operador->id);

        $previa = $this->service()->preview($this->contaId, '2026-01-01', '2026-01-31', 0, $this->itau->id);

        $this->assertSame('NF.291', $previa['lines'][0]['document_number']);
    }

    /**
     * Dois bancos da mesma empresa são dois extratos independentes: conciliar
     * um não pode bloquear o outro no mesmo mês.
     */
    public function test_dois_bancos_da_mesma_empresa_convivem_no_mesmo_periodo(): void
    {
        $this->service()->create($this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id, $this->itau->id);
        $this->service()->create($this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id, $this->segundoBanco()->id);

        $this->assertSame(2, PeriodStatement::query()->where('account_id', $this->contaId)->count());
    }

    public function test_o_banco_fica_congelado_no_relatorio_como_no_cabecalho_da_planilha(): void
    {
        $statement = $this->service()->create(
            $this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id, $this->itau->id,
        );

        $this->assertSame($this->itau->id, $statement->bank_account_id);
        $this->assertSame('Banco Itaú - Agência 6260 - C/C 13377-9', $statement->account_bank);
    }

    /**
     * O saldo inicial sugerido é o fechamento do MESMO banco. Puxar o de outro
     * banco daria um número errado com cara de conferido.
     */
    public function test_saldo_sugerido_nao_atravessa_de_um_banco_para_o_outro(): void
    {
        $anterior = $this->service()->create(
            $this->contaId, '2025-12-01', '2025-12-31', 777700, $this->operador->id, $this->itau->id,
        );
        $this->service()->close($anterior, $this->operador->id);

        $this->assertSame(
            777700,
            $this->service()->suggestedOpeningCents($this->contaId, '2026-01-01', $this->itau->id),
        );
        $this->assertNull(
            $this->service()->suggestedOpeningCents($this->contaId, '2026-01-01', $this->segundoBanco()->id),
        );
    }

    // ---------------------------------------------------------------------
    // Telas
    // ---------------------------------------------------------------------

    public function test_a_tela_mostra_o_bloco_em_aberto(): void
    {
        $this->titulo(FinancialTitleType::Receivable, '1234.00', 'R4', '2026-02-10', 'Prevent Senior');
        $statement = $this->service()->create(
            $this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id, $this->itau->id,
        );

        $this->actingAs($this->operador)
            ->get(route('period-statements.show', $statement))
            ->assertOk()
            ->assertSee('Em aberto no fim do período')
            ->assertSee('Prevent Senior');
    }

    /**
     * A equipe confere a conciliação contra a planilha do banco lendo dia a dia:
     * o que interessa é com quanto cada data fechou. A última linha de cada data
     * carrega esse saldo e é ela que fica marcada.
     *
     * O ponto do teste é o "última DE CADA DIA": num dia com vários movimentos,
     * só o último recebe a marca, e o dia seguinte recebe a sua própria.
     */
    public function test_o_saldo_do_ultimo_movimento_de_cada_dia_fica_marcado(): void
    {
        $r1 = $this->titulo(FinancialTitleType::Receivable, '100.00', 'R10', '2026-01-05', 'Cliente A');
        $r2 = $this->titulo(FinancialTitleType::Receivable, '200.00', 'R11', '2026-01-05', 'Cliente B');
        $p1 = $this->titulo(FinancialTitleType::Payable, '50.00', 'P10', '2026-01-06', 'Fornecedor C');
        $this->liquidar($r1, '100.00', '2026-01-05');
        $this->liquidar($r2, '200.00', '2026-01-05');
        $this->liquidar($p1, '50.00', '2026-01-06');

        $statement = $this->service()->create(
            $this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id, $this->itau->id,
        );

        $movimento = $statement->lines->reject->isPendente()->values();
        self::assertCount(3, $movimento);

        $html = $this->actingAs($this->operador)
            ->get(route('period-statements.show', $statement))
            ->assertOk()
            ->getContent();

        // Dia 05: duas linhas, e só a segunda fecha o dia (R$ 300,00).
        self::assertMatchesRegularExpression(
            '/saldo-do-dia"[^>]*>\s*R\$ 300,00/',
            $html,
            'O saldo do dia 05/01 (R$ 300,00) devia estar marcado.',
        );
        // Dia 06: uma linha só, que fecha o dia em R$ 250,00.
        self::assertMatchesRegularExpression(
            '/saldo-do-dia"[^>]*>\s*R\$ 250,00/',
            $html,
            'O saldo do dia 06/01 (R$ 250,00) devia estar marcado.',
        );
        // Exatamente dois dias com movimento, exatamente duas marcas.
        self::assertSame(2, substr_count($html, 'saldo-do-dia'));
    }

    /**
     * A folha de estilo sai carimbada com a data de modificação do arquivo.
     * Sem o carimbo, publicar uma correção de estilo no servidor não muda nada
     * para quem já abriu o sistema antes: o navegador serve a cópia em cache.
     */
    public function test_a_folha_de_estilo_sai_com_carimbo_de_versao(): void
    {
        $html = $this->actingAs($this->operador)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        self::assertMatchesRegularExpression(
            '/assets\/gestao\.css\?v=\d+/',
            $html,
            'O <link> do CSS devia carregar o carimbo de versão.',
        );
    }

    public function test_a_tela_de_criar_deixa_escolher_o_banco(): void
    {
        $this->segundoBanco();

        $this->actingAs($this->operador)
            ->get(route('period-statements.create', ['account_id' => $this->contaId]))
            ->assertOk()
            ->assertSee('Banco Itaú - Agência 6260 - C/C 13377-9')
            ->assertSee('Banco do Brasil - Agência 0929 - C/C 53120-0');
    }

    /**
     * O cabeçalho mostrava `contas.banco`, um texto solto da empresa, e ficava
     * "Banco: —" mesmo com uma conta escolhida no filtro. Tem de refletir a
     * conta bancária selecionada, como o cabeçalho da planilha.
     */
    public function test_o_cabecalho_mostra_a_conta_bancaria_escolhida(): void
    {
        $this->actingAs($this->operador)
            ->get(route('period-statements.create', [
                'account_id' => $this->contaId,
                'bank_account_id' => $this->segundoBanco()->id,
            ]))
            ->assertOk()
            ->assertSeeInOrder([
                'Conta:', 'Acop Files',
                'Banco:', 'Banco do Brasil - Agência 0929 - C/C 53120-0',
            ], false);
    }

    public function test_o_formulario_de_movimento_manual_tem_documento_e_banco(): void
    {
        $this->actingAs($this->operador)
            ->get(route('manual-movements.create'))
            ->assertOk()
            ->assertSee('Nº do documento')
            ->assertSee('Conta padrão da empresa')
            ->assertSee('Banco Itaú - Agência 6260 - C/C 13377-9');
    }

    public function test_criar_movimento_manual_pela_tela_grava_documento_e_banco(): void
    {
        $this->actingAs($this->operador)->post(route('manual-movements.store'), [
            'account_id' => $this->contaId,
            'bank_account_id' => $this->segundoBanco()->id,
            'document_number' => 'NF.345',
            'movement_date' => '2026-01-14',
            'direction' => 'IN',
            'amount' => '39.337,91',
            'history' => 'Unicamp (Depositou BB)',
        ])->assertRedirect();

        $this->assertDatabaseHas('manual_movements', [
            'document_number' => 'NF.345',
            'bank_account_id' => $this->segundoBanco()->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // Conciliação diária — from e to no mesmo dia
    // ---------------------------------------------------------------------

    /**
     * A conciliação é de UM dia (ADR-017), então `from` e `to` são iguais — e
     * era exatamente aí que o recorte falhava.
     *
     * `whereBetween(settlement_date, [dia, dia])` some com tudo no SQLite: o
     * cast `immutable_date` grava "2026-01-15 00:00:00", que é maior que
     * "2026-01-15" na comparação de string. No MariaDB a coluna é DATE e o
     * servidor trunca, então o defeito não aparecia em produção — o que o
     * torna pior, não melhor: mudar de driver mudaria o resultado financeiro.
     */
    public function test_a_conciliacao_de_um_unico_dia_traz_o_movimento_daquele_dia(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Payable, '410.00', 'P9', '2026-01-15', 'Fornecedor');
        $this->liquidar($titulo, '410.00', '2026-01-15');

        $previa = $this->service()->preview($this->contaId, '2026-01-15', '2026-01-15', 100000, $this->itau->id);

        $this->assertCount(1, $previa['lines'], 'o dia da baixa é o próprio dia da conciliação');
        $this->assertSame(41000, $previa['total_out_cents']);
        $this->assertSame(59000, $previa['closing_cents']);
    }

    public function test_a_conciliacao_de_um_dia_nao_traz_o_movimento_do_dia_seguinte(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Payable, '410.00', 'P10', '2026-01-16', 'Fornecedor');
        $this->liquidar($titulo, '410.00', '2026-01-16');

        $previa = $this->service()->preview($this->contaId, '2026-01-15', '2026-01-15', 100000, $this->itau->id);

        $this->assertCount(0, $previa['lines']);
        $this->assertSame(100000, $previa['closing_cents']);
    }

    /**
     * O aviso é o que impede a pendência de virar dinheiro sumido em silêncio.
     */
    public function test_a_tela_avisa_a_liquidacao_sem_conta_bancaria(): void
    {
        $this->segundoBanco();

        $titulo = $this->titulo(FinancialTitleType::Payable, '250.00', 'P11', '2026-01-15', 'Fornecedor');
        $this->liquidar($titulo, '250.00', '2026-01-15');

        $this->actingAs($this->operador)
            ->get(route('period-statements.create', [
                'account_id' => $this->contaId,
                'bank_account_id' => $this->itau->id,
                'from' => '2026-01-01',
                'to' => '2026-01-31',
            ]))
            ->assertOk()
            ->assertSee('sem conta bancária definida', false);
    }

    public function test_a_tela_nao_avisa_nada_quando_a_empresa_tem_uma_conta_so(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Payable, '250.00', 'P12', '2026-01-15', 'Fornecedor');
        $this->liquidar($titulo, '250.00', '2026-01-15');

        $this->actingAs($this->operador)
            ->get(route('period-statements.create', [
                'account_id' => $this->contaId,
                'bank_account_id' => $this->itau->id,
                'from' => '2026-01-01',
                'to' => '2026-01-31',
            ]))
            ->assertOk()
            ->assertDontSee('sem conta bancária definida', false);
    }
}
