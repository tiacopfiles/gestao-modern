<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A raiz é o endereço que a pessoa digita. No servidor ela quebrou com 405
 * depois de um `route:cache`, porque era uma closure e closure não sobrevive
 * à serialização do cache de rotas. Estes testes existem para que isso não
 * volte silenciosamente.
 */
class HomeRouteTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_raiz_leva_visitante_para_o_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_raiz_leva_autenticado_para_o_dashboard(): void
    {
        $user = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }

    /**
     * ATENÇÃO — esta suíte NÃO cobre o defeito real do servidor.
     *
     * Em 18/08/2026, no servidor 220, `GET /` passou a responder 405 depois de
     * um `route:cache`. Este teste passa mesmo com o cache ligado, porque o
     * cliente HTTP da suíte entrega o path já normalizado; no Apache a raiz do
     * diretório é servida pelo DirectoryIndex e chega diferente. Ou seja: verde
     * aqui não prova nada sobre aquilo — a causa raiz continua não confirmada.
     *
     * Enquanto não for confirmada, `route:cache` fica DESLIGADO naquele deploy
     * (`config:cache` continua obrigatório, por causa do vazamento de env entre
     * as aplicações Laravel que dividem o mesmo Apache). Quem rodar `optimize`
     * lá precisa rodar `route:clear` depois e conferir a raiz pelo navegador.
     */
    public function test_route_cache_nao_derruba_a_raiz_neste_ambiente(): void
    {
        $this->assertSame(0, Artisan::call('route:cache'), Artisan::output());

        try {
            $this->get('/')->assertRedirect(route('login'));
        } finally {
            Artisan::call('route:clear');
        }
    }
}
