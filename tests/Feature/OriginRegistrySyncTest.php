<?php

namespace Tests\Feature;

use App\Application\Integration\OriginRegistrySyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Importação dos cadastros das origens.
 *
 * O teste exercita o mapeamento sem tocar em rede: o que precisa ficar provado é
 * a regra, não a conexão. Em especial a armadilha de nome — a tabela
 * `fornecedor` de `contasareceber` guarda CLIENTES, não fornecedores.
 */
class OriginRegistrySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['categorias', 'tipos', 'situacoes', 'centrocusto'] as $tabela) {
            Schema::create($tabela, function (Blueprint $table): void {
                $table->increments('id');
                $table->string('nome');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::create('fornecedores', function (Blueprint $table): void {
            $this->camposDeParte($table);
        });

        Schema::create('clientes', function (Blueprint $table): void {
            $this->camposDeParte($table);
            $table->string('responsavel')->nullable();
            $table->string('cpf')->nullable();
        });
    }

    private function camposDeParte(Blueprint $table): void
    {
        $table->increments('id');
        $table->string('nome_fantasia')->nullable();
        $table->string('razao_social')->nullable();
        $table->string('cnpj')->nullable();
        $table->string('cep')->nullable();
        $table->string('estado')->nullable();
        $table->string('cidade')->nullable();
        $table->string('endereco')->nullable();
        $table->string('numero')->nullable();
        $table->string('complemento')->nullable();
        $table->string('bairro')->nullable();
        $table->string('email')->nullable();
        $table->string('telefone')->nullable();
        $table->string('celular')->nullable();
        $table->string('origem_sistema', 40)->nullable();
        $table->unsignedBigInteger('origem_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    /**
     * Homonimo nao pode ser fundido, e o mesmo registro nao pode duplicar.
     *
     * Os dois casos vieram dos dados reais: "Diego Donizete da Cunha Silva" sao
     * duas pessoas com CPFs diferentes em contasareceber, e "Alto Astral
     * Turismo" duplicou porque a chave era montada sobre valores diferentes dos
     * que iam ser gravados. Por isso a identidade e (origem, id na origem), e
     * nao o nome nem o documento — "001" aparece como CNPJ de fornecedores
     * diferentes em contas.
     */
    public function test_identidade_e_o_par_origem_mais_id_de_origem(): void
    {
        $servico = new OriginRegistrySyncService;
        $codigo = file_get_contents(base_path('app/Application/Integration/OriginRegistrySyncService.php'));

        $this->assertStringContainsString("\$chave = \$database.'#'.\$linha['id'];", $codigo,
            'a chave de identidade deixou de ser (origem, id de origem)');
        $this->assertStringNotContainsString('$chave = $this->chave($fantasiaGravada', $codigo,
            'voltou a deduplicar cadastro por nome, o que funde homonimos');
    }

    public function test_a_chave_de_nome_ainda_serve_para_cadastro_simples(): void
    {
        $servico = new OriginRegistrySyncService;
        $metodo = new \ReflectionMethod($servico, 'chave');
        $metodo->setAccessible(true);

        // Categoria, tipo e situacao nao tem id estavel util: sao listas curtas
        // de texto, e ali fundir "Manutencao Veiculo" com "Manutenção  Veículo"
        // e o comportamento desejado.
        $this->assertSame(
            $metodo->invoke($servico, 'Manutenção  Veículo'),
            $metodo->invoke($servico, 'manutencao veiculo'),
        );
        $this->assertNotSame(
            $metodo->invoke($servico, 'Aluguel Imóvel'),
            $metodo->invoke($servico, 'Aluguel Máquinas'),
        );
    }

    /**
     * Documenta o mapeamento que a origem esconde: `fornecedor` em
     * `contasareceber` são os clientes. Se alguém inverter isso, quem paga vira
     * quem cobra.
     */
    public function test_o_mapeamento_de_partes_separa_fornecedor_de_cliente(): void
    {
        $servico = new OriginRegistrySyncService;
        $propriedade = new \ReflectionMethod($servico, 'syncParties');

        $this->assertTrue($propriedade->isPrivate());

        // A regra viva está no sync(): contas -> fornecedores, contasareceber -> clientes.
        $codigo = file_get_contents(
            base_path('app/Application/Integration/OriginRegistrySyncService.php')
        );

        $this->assertStringContainsString("syncParties('contas', 'fornecedores')", $codigo);
        $this->assertStringContainsString("syncParties('contasareceber', 'clientes')", $codigo);
    }

    public function test_tabelas_de_destino_ausentes_nao_derrubam_a_importacao(): void
    {
        Schema::drop('categorias');

        $servico = new OriginRegistrySyncService;
        $metodo = new \ReflectionMethod($servico, 'syncSimple');
        $metodo->setAccessible(true);

        $stats = $metodo->invoke($servico, 'categoria', 'categorias');

        $this->assertSame(0, $stats['lidos']);
        $this->assertSame(0, $stats['criados']);
    }
}
