<?php

namespace Tests\Feature;

use App\Models\Conta;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cadastros vindos da origem são somente consulta.
 *
 * Quem cadastra fornecedor, cliente e categoria é Contas a Pagar / Contas a
 * Receber. Editar dos dois lados criaria duas verdades sobre o mesmo cadastro, e
 * a próxima sincronização resolveria a briga sozinha — apagando a edição sem
 * avisar ninguém. A trava fica no servidor, não só no botão escondido.
 */
class CadastrosSomenteConsultaTest extends TestCase
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
            $table->boolean('comercial')->default(true);
            $table->boolean('pagamentos')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['categorias', 'tipos', 'situacoes', 'centrocusto'] as $t) {
            Schema::create($t, function (Blueprint $table): void {
                $table->increments('id');
                $table->string('nome');
                $table->timestamps();
                $table->softDeletes();
            });
        }
        foreach (['clientes', 'fornecedores'] as $t) {
            Schema::create($t, function (Blueprint $table): void {
                $table->increments('id');
                $table->string('nome_fantasia')->nullable();
                $table->string('razao_social')->nullable();
                $table->string('cnpj')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome');
            $table->string('banco', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->operador = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
            'comercial' => true, 'pagamentos' => true,
        ]);
    }

    public static function cadastrosDaOrigem(): array
    {
        return [
            'clientes' => ['clientes'],
            'fornecedores' => ['fornecedores'],
            'categorias' => ['categorias'],
            'tipos' => ['tipos'],
            'situacoes' => ['situacoes'],
            'centros de custo' => ['centros-custo'],
        ];
    }

    #[DataProvider('cadastrosDaOrigem')]
    public function test_listar_continua_permitido(string $kind): void
    {
        // Consultar continua liberado: o que a trava proibe e escrever.
        $this->actingAs($this->operador)
            ->get("/cadastros/{$kind}")
            ->assertOk()
            ->assertSee('Sincronizado de Contas a Pagar', false);
    }

    #[DataProvider('cadastrosDaOrigem')]
    public function test_criar_e_bloqueado_no_servidor(string $kind): void
    {
        $this->actingAs($this->operador)->get("/cadastros/{$kind}/novo")->assertForbidden();
        $this->actingAs($this->operador)->post("/cadastros/{$kind}", ['nome' => 'X'])->assertForbidden();
    }

    #[DataProvider('cadastrosDaOrigem')]
    public function test_a_tela_nao_oferece_botao_de_novo(string $kind): void
    {
        $this->actingAs($this->operador)
            ->get("/cadastros/{$kind}")
            ->assertOk()
            ->assertDontSee('+ Novo cadastro', false)
            ->assertSee('Somente consulta', false);
    }

    /**
     * Contas bancárias continua editável: o campo `banco` é do Gestão e não vem
     * de origem nenhuma — sem poder preenchê-lo, o cabeçalho da conciliação fica
     * sem o banco.
     */
    public function test_contas_bancarias_continua_editavel(): void
    {
        Conta::query()->create(['nome' => 'Acop Files']);

        $this->actingAs($this->operador)
            ->get('/cadastros/contas')
            ->assertOk()
            ->assertSee('+ Novo cadastro', false)
            ->assertDontSee('Somente consulta', false);
    }
}
