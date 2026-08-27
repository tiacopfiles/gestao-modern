<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesLegacyWitnessTables;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

/**
 * O dashboard soma valores do legado e, quando a conciliação v2 está ligada,
 * também resume o núcleo moderno. O painel moderno segue o mesmo critério do
 * menu (flag + permissão de leitura) para não anunciar um módulo que o usuário
 * não consegue abrir.
 */
class DashboardTest extends TestCase
{
    use CreatesLegacyWitnessTables;
    use RefreshesTestDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        // Schema::create e não SQL cru: o prefixo de conexão (`avt_`) precisa ser
        // aplicado, igual à aplicação real.
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
        $this->createLegacyWitnessTables();

        $this->operator = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);
    }

    public function test_dashboard_opens_and_totals_legacy_amounts_without_errors(): void
    {
        DB::table('lancamentos')->insert([
            'marker' => 'sintetico', 'fornecedor' => 'Fornecedor Sintético', 'situacao' => '1',
            'data_vencimento' => now()->addDays(3)->toDateString(), 'valor' => 100, 'valor_total' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recebimentos')->insert([
            'marker' => 'sintetico', 'cliente' => 'Cliente Sintético', 'situacao' => '1',
            'data_vencimento' => now()->addDays(4)->toDateString(), 'valor' => 250, 'valor_total' => 250,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->operator)->get('/dashboard')
            ->assertOk()
            ->assertSee('A receber')
            ->assertSee('A pagar');
    }

    public function test_modern_panel_is_hidden_without_the_flag_or_without_permission(): void
    {
        config([
            'reconciliation.v2_enabled' => false,
            'reconciliation.view_user_ids' => [$this->operator->id],
            'reconciliation.manage_user_ids' => [],
        ]);
        $this->actingAs($this->operator)->get('/dashboard')->assertOk()->assertDontSee('A pagar em aberto', false);

        // Flag ligada mas sem permissão de leitura: também não deve aparecer.
        // `reconciliation:view` soma view_user_ids + manage_user_ids, então as
        // duas listas precisam estar vazias para representar "sem acesso".
        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [],
            'reconciliation.manage_user_ids' => [],
        ]);
        $this->actingAs($this->operator)->get('/dashboard')->assertOk()->assertDontSee('A pagar em aberto', false);
    }

    public function test_modern_panel_appears_with_flag_and_permission(): void
    {
        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operator->id],
        ]);

        $this->actingAs($this->operator)->get('/dashboard')
            ->assertOk()
            ->assertSee('A pagar em aberto', false)
            ->assertSee('A receber em aberto', false)
            ->assertSee('Resultado do período', false)
            // Os dois universos ficam rotulados e separados: um número que
            // somasse legado e moderno não teria significado.
            ->assertSee('Base legada', false);
    }

    public function test_dashboard_still_opens_when_the_legacy_tables_do_not_exist(): void
    {
        // Um ambiente dedicado a testar a integração não tem as tabelas legadas.
        // A seção correspondente some; a tela não pode cair.
        Schema::drop('lancamentos');
        Schema::drop('recebimentos');
        Schema::drop('movimentos');

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operator->id],
        ]);

        $this->actingAs($this->operator)->get('/dashboard')
            ->assertOk()
            ->assertSee('A pagar em aberto', false)
            ->assertDontSee('Base legada', false);
    }
}
