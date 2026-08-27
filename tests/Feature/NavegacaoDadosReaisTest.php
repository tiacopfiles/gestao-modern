<?php

namespace Tests\Feature;

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
use Tests\Support\CreatesLegacyWitnessTables;
use Tests\TestCase;

/**
 * Quem entra no sistema clica em "Contas a pagar" no menu. Se esse item levar
 * para a base legada — vazia num ambiente alimentado por sincronizacao — a
 * pessoa conclui que a importacao falhou. Foi o que aconteceu no servidor 220
 * em 18/08/2026, com 13 mil titulos importados e a tela mostrando nada.
 */
class NavegacaoDadosReaisTest extends TestCase
{
    use CreatesLegacyWitnessTables, RefreshDatabase;

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

        // Tabelas de apoio herdadas do schema legado: as telas as consultam para
        // montar rótulos e filtros. Não têm migration própria no projeto.
        foreach (['contas', 'tipos', 'situacoes', 'categorias', 'centrocusto', 'fornecedores', 'clientes'] as $lookup) {
            Schema::create($lookup, function (Blueprint $table): void {
                $table->increments('id');
                $table->string('nome')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        DB::table('contas')->insert(['id' => 1, 'nome' => 'Conta sintética', 'created_at' => now(), 'updated_at' => now()]);

        $this->operador = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);

        config()->set('reconciliation.v2_enabled', true);
        config()->set('reconciliation.view_user_ids', [$this->operador->id]);
        config()->set('reconciliation.manage_user_ids', [$this->operador->id]);
    }

    /**
     * Passa pelo serviço de ingestão de verdade, e não por um create cru: é o
     * mesmo caminho que a sincronização usa, então o título nasce com hash,
     * parcelas e auditoria como nasceria em produção.
     */
    private function titulo(FinancialTitleType $tipo, string $parte): FinancialTitle
    {
        $codigo = $tipo === FinancialTitleType::Payable ? 'LEGACY_PAYABLE' : 'LEGACY_RECEIVABLE';

        SourceSystem::query()->firstOrCreate(
            ['code' => $codigo],
            ['name' => 'Origem legada', 'active' => true],
        );

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: $codigo,
            externalId: 'ext-'.uniqid(),
            type: $tipo,
            issueDate: '2026-01-01',
            dueDate: '2026-02-01',
            originalAmount: '100.00',
            discountAmount: '0.00',
            additionAmount: '0.00',
            partyName: $parte,
            documentNumber: 'DOC-1',
            currency: 'BRL',
            installmentCount: 1,
        ))->title;
    }

    public function test_menu_leva_contas_a_pagar_para_os_titulos_sincronizados(): void
    {
        $this->actingAs($this->operador)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('titles.index', ['type' => 'PAYABLE']), false)
            ->assertSee(route('titles.index', ['type' => 'RECEIVABLE']), false);
    }

    public function test_titulos_filtra_por_pagar_e_receber(): void
    {
        $this->titulo(FinancialTitleType::Payable, 'Fornecedor Real');
        $this->titulo(FinancialTitleType::Receivable, 'Cliente Real');

        $this->actingAs($this->operador)
            ->get('/titulos?type=PAYABLE')
            ->assertOk()
            ->assertSee('Fornecedor Real')
            ->assertDontSee('Cliente Real');

        $this->actingAs($this->operador)
            ->get('/titulos?type=RECEIVABLE')
            ->assertOk()
            ->assertSee('Cliente Real')
            ->assertDontSee('Fornecedor Real');
    }

    public function test_tela_legada_vazia_aponta_para_onde_os_dados_estao(): void
    {
        $this->createLegacyWitnessTables();
        DB::table('lancamentos')->delete();

        $this->actingAs($this->operador)
            ->get('/contas-a-pagar')
            ->assertOk()
            ->assertSee('base legada', false)
            ->assertSee(route('titles.index', ['type' => 'PAYABLE']), false);
    }

    /**
     * Numa instalacao alimentada por sincronizacao a camada legada nao tem uso,
     * fica vazia para sempre e ainda oferece "+ Novo titulo" — um registro
     * criado ali nao aparece na conciliacao, no extrato nem nos totais. Com a
     * flag desligada ela some do menu e do painel.
     */
    public function test_camada_legada_pode_ser_escondida_por_configuracao(): void
    {
        $this->createLegacyWitnessTables();
        config()->set('gestao.legacy_ui', false);

        $this->actingAs($this->operador)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('A pagar (legado)', false)
            ->assertDontSee('Base legada', false)
            // o que importa continua no lugar
            ->assertSee(route('titles.index', ['type' => 'PAYABLE']), false);
    }

    public function test_camada_legada_aparece_quando_a_flag_esta_ligada(): void
    {
        $this->createLegacyWitnessTables();
        config()->set('gestao.legacy_ui', true);

        $this->actingAs($this->operador)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('A pagar (legado)', false);
    }
}
