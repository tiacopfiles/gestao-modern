<?php

namespace Tests\Feature;

use App\Application\Integration\OriginSyncService;
use App\Models\SyncCycle;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OriginSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `users` é herdada do schema legado e não tem migration própria, então
        // a suíte a cria com Schema::create para que o prefixo de conexão valha.
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
    }

    private function operador(): User
    {
        return User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);
    }

    /**
     * O guard é a única coisa que separa "ler a origem" de "escrever na origem"
     * quando alguém aponta a conexão para o lugar errado. Se ele deixar passar
     * um banco de origem, o Gestão pode corromper o sistema das funcionárias.
     */
    #[DataProvider('bancosProibidos')]
    public function test_sincronizacao_recusa_banco_de_origem_como_destino(string $banco): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/origem ou sistema já publicado/');

        OriginSyncService::assertDestinationIsWritable($banco);
    }

    public static function bancosProibidos(): array
    {
        return [
            'contas a pagar' => ['contas'],
            'contas a receber' => ['contasareceber'],
            'homologacao da origem' => ['contasareceber_homologacao'],
            'qa da origem' => ['contasareceber_review_qa'],
            'gestao legado publicado' => ['gestao'],
        ];
    }

    public function test_sincronizacao_recusa_banco_desconhecido(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/não parece ser um banco próprio/');

        OriginSyncService::assertDestinationIsWritable('algum_banco_qualquer');
    }

    #[DataProvider('bancosPermitidos')]
    public function test_sincronizacao_aceita_banco_proprio_do_gestao(string $banco): void
    {
        OriginSyncService::assertDestinationIsWritable($banco);

        $this->addToAssertionCount(1);
    }

    public static function bancosPermitidos(): array
    {
        return [
            'banco do servidor' => ['gestao_conciliacao'],
            'sqlite do teste de integracao' => ['database/integracao_real_test.sqlite'],
        ];
    }

    public function test_dashboard_mostra_painel_de_sincronizacao_sem_ciclo_nenhum(): void
    {
        $user = $this->operador();

        // O botao so aparece para quem pode sincronizar; o indicador aparece
        // para todo mundo, porque saber se o dado esta atualizado nao e
        // privilegio de quem executa.
        config(['reconciliation.manage_user_ids' => [$user->id]]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Sincronizar agora', false)
            ->assertSee('Nunca sincronizado', false);
    }

    public function test_dashboard_mostra_o_ultimo_ciclo_de_cada_origem(): void
    {
        $user = $this->operador();

        $ciclo = SyncCycle::create([
            'source_code' => 'LEGACY_PAYABLE',
            'trigger' => 'manual',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
            'source_rows_read' => 10,
            'created_count' => 3,
            'updated_count' => 2,
            'settlements_created' => 5,
            'status' => 'OK',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            // O painel mostra data/hora do ciclo; os numeros aparecem no
            // resultado da sincronizacao, nao no indicador permanente.
            ->assertSee($ciclo->started_at->format('d/m/Y'), false);
    }

    public function test_ciclo_com_erro_aparece_como_erro_e_nao_e_escondido(): void
    {
        $user = $this->operador();

        SyncCycle::create([
            'source_code' => 'LEGACY_RECEIVABLE',
            'trigger' => 'scheduled',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_count' => 7,
            'status' => 'ERROR',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            // Falha nao pode ficar escondida: aparece contada, em linguagem
            // de usuario — nao com o enum tecnico.
            ->assertSee('7 não aplicado(s)', false);
    }

    public function test_rota_de_sincronizacao_exige_autenticacao(): void
    {
        $this->post('/sincronizar')->assertRedirect('/login');
    }

    /**
     * O MariaDB 10.1 do servidor roda com explicit_defaults_for_timestamp=0: a
     * primeira coluna TIMESTAMP de uma tabela ganha ON UPDATE CURRENT_TIMESTAMP
     * e é reescrita a cada save. Se started_at fosse TIMESTAMP, o início do
     * ciclo passaria a ser a hora do fim — e o ciclo existe justamente para
     * datar com precisão o que foi lido da origem.
     */
    public function test_inicio_do_ciclo_nao_e_reescrito_ao_salvar(): void
    {
        $inicio = now()->subMinutes(30);

        $cycle = SyncCycle::create([
            'source_code' => 'LEGACY_PAYABLE',
            'trigger' => 'cli',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'started_at' => $inicio,
            'status' => 'RUNNING',
        ]);

        $cycle->finished_at = now();
        $cycle->status = 'OK';
        $cycle->save();

        $this->assertSame(
            $inicio->format('Y-m-d H:i:s'),
            $cycle->fresh()->started_at->format('Y-m-d H:i:s'),
            'started_at foi alterado ao salvar o ciclo.'
        );
    }

    /**
     * Classificar um título já liquidado não pode falhar.
     *
     * Categoria e centro de custo sao rotulos, nao dinheiro. Quando passavam
     * pelo payload da ingestao, mudavam o hash de todos os titulos e faziam
     * 11 mil liquidados estourarem "titulo liquidado nao pode ser atualizado"
     * numa unica sincronizacao — por uma mudanca que nem e financeira.
     */
    public function test_classificacao_nao_passa_pela_regra_de_titulo_liquidado(): void
    {
        $codigo = file_get_contents(base_path('app/Application/Integration/OriginSyncService.php'));

        $this->assertStringNotContainsString('categoryId: $categorias', $codigo,
            'categoria voltou para o payload da ingestao e vai derrubar a sincronizacao dos liquidados');
        $this->assertStringContainsString('aplicarClassificacao', $codigo);
        $this->assertStringContainsString("DB::table('financial_titles')->where('id', \$title->id)->update(\$mudancas)", $codigo);
    }
}
