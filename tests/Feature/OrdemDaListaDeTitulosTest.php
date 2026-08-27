<?php

namespace Tests\Feature;

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
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
 * A lista de títulos é lida de cima para baixo por quem vai trabalhar nela: o
 * que ainda vai vencer é o que dá para planejar, o que já venceu é o que cobra
 * ação hoje, e o que foi baixado é histórico. Ordenar só por vencimento
 * misturava as três leituras — um título pago de janeiro aparecia acima de um
 * vencido de agosto.
 */
class OrdemDaListaDeTitulosTest extends TestCase
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

        // A tela monta o filtro de conta a partir da tabela legada `contas`.
        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('contas')->insert(['id' => 1, 'nome' => 'Conta sintética', 'created_at' => now(), 'updated_at' => now()]);

        $this->operador = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);

        config()->set('reconciliation.v2_enabled', true);
        config()->set('reconciliation.view_user_ids', [$this->operador->id]);
        config()->set('reconciliation.manage_user_ids', [$this->operador->id]);
    }

    private function titulo(string $parte, string $vencimento): FinancialTitle
    {
        SourceSystem::query()->firstOrCreate(
            ['code' => 'LEGACY_PAYABLE'],
            ['name' => 'Origem legada', 'active' => true],
        );

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'LEGACY_PAYABLE',
            externalId: 'ext-'.uniqid(),
            type: FinancialTitleType::Payable,
            issueDate: '2026-01-01',
            dueDate: $vencimento,
            originalAmount: '100.00',
            discountAmount: '0.00',
            additionAmount: '0.00',
            partyName: $parte,
            documentNumber: 'DOC-1',
            currency: 'BRL',
            installmentCount: 1,
        ))->title;
    }

    /**
     * @param  array<int, string>  $nomes
     */
    private function ordemNaTela(string $html, array $nomes): array
    {
        $posicoes = [];
        foreach ($nomes as $nome) {
            $posicoes[$nome] = strpos($html, $nome);
        }
        asort($posicoes);

        return array_keys($posicoes);
    }

    public function test_lista_traz_a_vencer_depois_vencido_depois_baixado(): void
    {
        // Criados fora de ordem de propósito: se a ordenação caísse para
        // `id`/`due_date` puro, o resultado sairia diferente do esperado.
        $pago = $this->titulo('Fornecedor Pago', now()->subMonths(2)->toDateString());
        app(SettlementService::class)->settle(
            titleId: $pago->id,
            amount: '100.00',
            settlementDate: now()->subMonth()->toDateString(),
            actorId: $this->operador->id,
        );

        $this->titulo('Fornecedor Vencido Novo', now()->subDay()->toDateString());
        $this->titulo('Fornecedor A Vencer', now()->addMonth()->toDateString());
        $this->titulo('Fornecedor Vencido Antigo', now()->subMonths(3)->toDateString());

        $html = $this->actingAs($this->operador)
            ->get('/titulos?type=PAYABLE')
            ->assertOk()
            ->getContent();

        $this->assertSame([
            'Fornecedor A Vencer',
            'Fornecedor Vencido Antigo',
            'Fornecedor Vencido Novo',
            'Fornecedor Pago',
        ], $this->ordemNaTela($html, [
            'Fornecedor Pago',
            'Fornecedor Vencido Novo',
            'Fornecedor A Vencer',
            'Fornecedor Vencido Antigo',
        ]));
    }

    /**
     * O título que vence hoje ainda não está vencido para quem paga, mas o badge
     * da tela o marca como "Vencido" desde antes desta mudança. As duas leituras
     * têm de contar a mesma história, então ele fica no grupo dos vencidos.
     */
    public function test_vence_hoje_acompanha_o_badge_e_fica_com_os_vencidos(): void
    {
        $this->titulo('Fornecedor Amanha', now()->addDay()->toDateString());
        $this->titulo('Fornecedor Hoje', now()->toDateString());

        $html = $this->actingAs($this->operador)
            ->get('/titulos?type=PAYABLE')
            ->assertOk()
            ->getContent();

        $this->assertSame(
            ['Fornecedor Amanha', 'Fornecedor Hoje'],
            $this->ordemNaTela($html, ['Fornecedor Hoje', 'Fornecedor Amanha']),
        );
    }

    /**
     * A tela deixou de abrir filtrada em "Em aberto": esconder o que já foi pago
     * é justamente o que a nova ordem torna desnecessário.
     */
    public function test_tela_abre_mostrando_tambem_o_que_ja_foi_baixado(): void
    {
        $pago = $this->titulo('Fornecedor Quitado', now()->subMonths(2)->toDateString());
        app(SettlementService::class)->settle(
            titleId: $pago->id,
            amount: '100.00',
            settlementDate: now()->subMonth()->toDateString(),
            actorId: $this->operador->id,
        );

        $this->actingAs($this->operador)
            ->get('/titulos?type=PAYABLE')
            ->assertOk()
            ->assertSee('Fornecedor Quitado');
    }

    public function test_filtro_explicito_de_situacao_continua_valendo(): void
    {
        $pago = $this->titulo('Fornecedor Quitado', now()->subMonths(2)->toDateString());
        app(SettlementService::class)->settle(
            titleId: $pago->id,
            amount: '100.00',
            settlementDate: now()->subMonth()->toDateString(),
            actorId: $this->operador->id,
        );
        $this->titulo('Fornecedor Em Aberto', now()->addMonth()->toDateString());

        $this->actingAs($this->operador)
            ->get('/titulos?type=PAYABLE&status=OPEN')
            ->assertOk()
            ->assertSee('Fornecedor Em Aberto')
            ->assertDontSee('Fornecedor Quitado');
    }
}
