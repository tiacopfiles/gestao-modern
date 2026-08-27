<?php

namespace Tests\Feature;

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Money;
use App\Domain\Financial\TitleIngestionData;
use App\Models\FinancialTitle;
use App\Models\SourceSystem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * NOTA DE SEMÂNTICA: "em aberto" no painel é a posição de agora e não respeita
 * período — uma dívida pendente não pertence a um mês. "Realizado" é movimento e
 * segue o período escolhido. Por isso os testes abaixo pedem um período largo: o
 * que eles comparam é o cálculo, não o recorte.
 *
 * O dashboard passou a somar em SQL em vez de percorrer os títulos em PHP,
 * porque com 13 mil títulos reais a tela levava 13 segundos e disparava 13.122
 * consultas. Trocar o cálculo de lugar só vale se a resposta continuar
 * idêntica — inclusive nos casos que o agregado erraria se fosse ingênuo:
 * estorno, liquidação parcial e título liquidado a maior.
 */
class DashboardTotaisAgregadosTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

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
            $table->timestamps();
            $table->softDeletes();
        });

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

    private function titulo(FinancialTitleType $tipo, string $valor, string $externo): FinancialTitle
    {
        $codigo = $tipo === FinancialTitleType::Payable ? 'LEGACY_PAYABLE' : 'LEGACY_RECEIVABLE';
        SourceSystem::query()->firstOrCreate(['code' => $codigo], ['name' => 'Origem', 'active' => true]);

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: $codigo,
            externalId: $externo,
            type: $tipo,
            issueDate: '2026-01-01',
            dueDate: '2026-02-01',
            originalAmount: $valor,
            discountAmount: '0.00',
            additionAmount: '0.00',
            currency: 'BRL',
            installmentCount: 1,
        ))->title;
    }

    private function liquidar(FinancialTitle $titulo, string $valor, string $externo): void
    {
        app(SettlementService::class)->settle(
            titleId: $titulo->id,
            amount: $valor,
            settlementDate: '2026-02-01',
            installmentId: $titulo->installments()->first()?->id,
            sourceSystemId: $titulo->source_system_id,
            externalId: $externo,
        );
    }

    /**
     * A referência: o mesmo cálculo do jeito antigo, título a título em PHP.
     *
     * @return array{open_payable: int, open_receivable: int, settled_payable: int, settled_receivable: int}
     */
    private function calculoAntigoEmPhp(): array
    {
        $r = ['open_payable' => 0, 'open_receivable' => 0, 'settled_payable' => 0, 'settled_receivable' => 0];

        foreach (FinancialTitle::query()->where('status', '!=', 'CANCELLED')->get() as $titulo) {
            $restante = $titulo->remainingCents();
            $liquidado = Money::toCents((string) $titulo->total_amount) - $restante;

            if ($titulo->type === FinancialTitleType::Payable) {
                $r['open_payable'] += $restante;
                $r['settled_payable'] += $liquidado;
            } else {
                $r['open_receivable'] += $restante;
                $r['settled_receivable'] += $liquidado;
            }
        }

        return $r;
    }

    private function totaisDaTela(): array
    {
        // Periodo largo de proposito: "realizado" no painel passou a respeitar o
        // periodo escolhido, e aqui o que se compara e o calculo, nao o recorte.
        $resposta = $this->actingAs($this->operador)
            ->get('/dashboard?periodo=personalizado&de=2020-01-01&ate=2030-12-31');
        $resposta->assertOk();

        $modern = $resposta->viewData('modern');

        return [
            'open_payable' => $modern['open_payable_cents'],
            'open_receivable' => $modern['open_receivable_cents'],
            'settled_payable' => $modern['settled_payable_cents'],
            'settled_receivable' => $modern['settled_receivable_cents'],
        ];
    }

    public function test_agregado_bate_com_o_calculo_antigo_em_cenario_completo(): void
    {
        // aberto
        $this->titulo(FinancialTitleType::Payable, '1000.00', 'p-aberto');
        $this->titulo(FinancialTitleType::Receivable, '2000.00', 'r-aberto');

        // liquidado por inteiro
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '500.00', 'p-pago'), '500.00', 'liq-p-pago');
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '800.00', 'r-pago'), '800.00', 'liq-r-pago');

        // parcial
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '400.00', 'p-parcial'), '150.00', 'liq-p-parcial');

        $esperado = $this->calculoAntigoEmPhp();

        $this->assertSame($esperado, $this->totaisDaTela());
    }

    public function test_estorno_volta_a_abrir_o_titulo_nos_dois_calculos(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Receivable, '300.00', 'r-estorno');
        $this->liquidar($titulo, '300.00', 'liq-r-estorno');

        DB::table('title_settlements')->insert([
            'financial_title_id' => $titulo->id,
            'source_system_id' => $titulo->source_system_id,
            'external_id' => 'estorno-1',
            'type' => 'REVERSAL',
            'status' => 'CONFIRMED',
            'amount' => '300.00',
            'settlement_date' => '2026-03-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $esperado = $this->calculoAntigoEmPhp();

        $this->assertSame(30000, $esperado['open_receivable'], 'o estorno deveria reabrir o título');
        $this->assertSame($esperado, $this->totaisDaTela());
    }

    /**
     * O caso que um agregado ingênuo erra: sem o piso por título, o excesso de
     * um título liquidado a maior abateria o saldo em aberto de outro.
     */
    public function test_titulo_liquidado_a_maior_nao_abate_o_saldo_de_outro(): void
    {
        $demais = $this->titulo(FinancialTitleType::Payable, '100.00', 'p-a-maior');
        $this->liquidar($demais, '100.00', 'liq-a-maior-1');

        DB::table('title_settlements')->insert([
            'financial_title_id' => $demais->id,
            'source_system_id' => $demais->source_system_id,
            'external_id' => 'liq-a-maior-2',
            'type' => 'PAYMENT',
            'status' => 'CONFIRMED',
            'amount' => '900.00',
            'settlement_date' => '2026-02-02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->titulo(FinancialTitleType::Payable, '700.00', 'p-intocado');

        $totais = $this->totaisDaTela();

        // O que importa aqui: o excesso NAO pode abater o saldo em aberto de
        // outro titulo. Continua R$ 700,00 do titulo intocado.
        $this->assertSame(70000, $totais['open_payable'], 'o excesso de um título vazou para o saldo de outro');
        $this->assertSame($this->calculoAntigoEmPhp()['open_payable'], $totais['open_payable']);

        // Ja o "realizado" difere de proposito do calculo antigo neste caso:
        // pagaram R$ 1.000,00 por um titulo de R$ 100,00, e do caixa saiu
        // R$ 1.000,00. O card de movimento mostra o dinheiro que saiu; o antigo
        // capava no valor do titulo e escondia o excesso.
        $this->assertSame(100000, $totais['settled_payable'], 'o card deveria mostrar o que saiu do caixa');
        $this->assertSame(10000, $this->calculoAntigoEmPhp()['settled_payable'], 'o cálculo antigo capava no valor do título');
    }

    /**
     * Liquidação não confirmada não pode contar como realizada.
     */
    public function test_liquidacao_nao_confirmada_nao_conta(): void
    {
        $titulo = $this->titulo(FinancialTitleType::Payable, '250.00', 'p-pendente');

        DB::table('title_settlements')->insert([
            'financial_title_id' => $titulo->id,
            'source_system_id' => $titulo->source_system_id,
            'external_id' => 'liq-pendente',
            'type' => 'PAYMENT',
            'status' => 'PENDING',
            'amount' => '250.00',
            'settlement_date' => '2026-02-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $totais = $this->totaisDaTela();

        $this->assertSame(25000, $totais['open_payable']);
        $this->assertSame(0, $totais['settled_payable']);
        $this->assertSame($this->calculoAntigoEmPhp(), $totais);
    }

    /**
     * O ganho que motivou a mudança: número de consultas deixa de crescer com
     * a quantidade de títulos.
     */
    public function test_o_dashboard_nao_faz_uma_consulta_por_titulo(): void
    {
        foreach (range(1, 40) as $i) {
            $titulo = $this->titulo(FinancialTitleType::Payable, '10.00', "p-{$i}");
            if ($i % 2 === 0) {
                $this->liquidar($titulo, '10.00', "liq-p-{$i}");
            }
        }

        DB::enableQueryLog();
        $this->actingAs($this->operador)->get('/dashboard')->assertOk();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            40,
            $consultas,
            "O dashboard emitiu {$consultas} consultas para 40 títulos — o N+1 voltou."
        );
    }
}
