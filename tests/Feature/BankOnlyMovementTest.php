<?php

namespace Tests\Feature;

use App\Application\Reconciliation\BankOnlyMovementService;
use App\Domain\Reconciliation\Enums\BankOnlyKind;
use App\Models\BankOnlyMovement;
use App\Models\BankTransaction;
use App\Models\Conta;
use App\Models\FinancialTitle;
use App\Models\ImportBatch;
use App\Models\SourceSystem;
use App\Models\User;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

/**
 * Movimento que só existe no banco.
 *
 * Tarifa, rendimento e transferência entre contas do grupo somam R$ 230.151,24
 * num único mês da Acop Files e R$ 481.088,73 em saídas na Global Box. Nenhum
 * tem título, e nenhum vai ter. Sem uma forma de declarar isso, eles ficam
 * eternamente na fila de "título não encontrado" — e uma fila que nunca zera
 * ensina o operador a ignorá-la inteira.
 */
class BankOnlyMovementTest extends TestCase
{
    use RefreshesTestDatabase;

    private User $operador;

    private static int $seq = 0;

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
        Conta::query()->create(['id' => 1, 'nome' => 'Acop Files']);

        $this->operador = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);
    }

    private function transacao(string $direcao, string $valor, string $descricao): BankTransaction
    {
        $id = ++self::$seq;
        $source = SourceSystem::query()->where('code', 'BANK_IMPORT')->firstOrFail();
        $batch = ImportBatch::query()->create([
            'source_system_id' => $source->id, 'account_id' => 1, 'channel' => 'API',
            'format' => 'CANONICAL_API', 'status' => 'COMPLETED', 'total_items' => 1,
            'imported_items' => 1, 'correlation_id' => (string) Str::uuid(),
            'started_at' => now(), 'completed_at' => now(),
        ]);

        return BankTransaction::query()->create([
            'account_id' => 1, 'source_system_id' => $source->id, 'import_batch_id' => $batch->id,
            'external_id' => "BO-{$id}", 'identity_quality' => 'STRONG',
            'direction' => $direcao, 'amount' => $valor, 'currency' => 'BRL',
            'transaction_date' => '2026-01-15', 'description_original' => $descricao,
            'payload_hash' => hash('sha256', "p{$id}"), 'raw_hash' => hash('sha256', "r{$id}"),
        ]);
    }

    /**
     * Os textos são os reais das planilhas.
     *
     * @dataProvider textosDoExtrato
     */
    public function test_sugere_o_tipo_a_partir_do_texto_do_extrato(string $texto, ?BankOnlyKind $esperado): void
    {
        $this->assertSame($esperado, app(BankOnlyMovementService::class)->suggest($texto));
    }

    public static function textosDoExtrato(): array
    {
        return [
            'rendimento' => ['Rend Pago Aplic Aut APR', BankOnlyKind::Rendimento],
            'rendimento mais' => ['Rend Pago Aplic Aut Mais', BankOnlyKind::Rendimento],
            'tarifa cobranca' => ['TAR Cobrança EXP', BankOnlyKind::Tarifa],
            'tarifa conta certa' => ['Tar Conta Certa 12/19', BankOnlyKind::Tarifa],
            'transferencia' => ['Transferência Itaú agência 9697 c/c 07155-4 Sr.Marco Antonio Colitti', BankOnlyKind::TransferenciaInterna],
            'titulo de verdade' => ['V.06/01 Embalimp NF.237058', null],
            'deposito de cliente' => ['V.01/01 Sirio Libanes', null],
        ];
    }

    public function test_classificar_explica_o_movimento_sem_criar_titulo(): void
    {
        $tarifa = $this->transacao('DEBIT', '75.62', 'TAR Cobrança EXP');

        $mov = app(BankOnlyMovementService::class)->classify(
            $tarifa->id, BankOnlyKind::Tarifa, $this->operador->id,
        );

        $this->assertSame(BankOnlyKind::Tarifa, $mov->kind);
        $this->assertSame(0, FinancialTitle::query()->count(), 'criou título para uma tarifa');
        $this->assertSame(0, $tarifa->fresh()->reconciliationAllocations()->count());
    }

    public function test_classificar_tira_o_movimento_da_fila_de_pendencia(): void
    {
        $tarifa = $this->transacao('DEBIT', '75.62', 'TAR Cobrança EXP');
        $semTitulo = $this->transacao('DEBIT', '1234.00', 'V.10/01 Fornecedor sem match');

        $servico = app(BankOnlyMovementService::class);

        $this->assertCount(2, $servico->pending(1, '2026-01-01', '2026-01-31'));

        $servico->classify($tarifa->id, BankOnlyKind::Tarifa, $this->operador->id);

        $pendentes = $servico->pending(1, '2026-01-01', '2026-01-31');
        $this->assertCount(1, $pendentes, 'a tarifa continuou pendente depois de classificada');
        $this->assertSame($semTitulo->id, $pendentes->first()->id);
    }

    public function test_outro_exige_justificativa(): void
    {
        $tx = $this->transacao('DEBIT', '10.00', 'Debito estranho');

        $this->expectException(DomainException::class);
        app(BankOnlyMovementService::class)->classify($tx->id, BankOnlyKind::Outro, $this->operador->id);
    }

    public function test_outro_com_justificativa_e_aceito(): void
    {
        $tx = $this->transacao('DEBIT', '10.00', 'Debito estranho');

        $mov = app(BankOnlyMovementService::class)->classify(
            $tx->id, BankOnlyKind::Outro, $this->operador->id, 'Débito de custódia acordado com o gerente.',
        );

        $this->assertStringContainsString('custódia', (string) $mov->justification);
    }

    public function test_uma_transacao_tem_no_maximo_uma_classificacao(): void
    {
        $tx = $this->transacao('CREDIT', '0.09', 'Rend Pago Aplic Aut APR');
        $servico = app(BankOnlyMovementService::class);

        $servico->classify($tx->id, BankOnlyKind::Rendimento, $this->operador->id);
        $servico->classify($tx->id, BankOnlyKind::Tarifa, $this->operador->id);

        $this->assertSame(1, BankOnlyMovement::query()->where('bank_transaction_id', $tx->id)->count());
        $this->assertSame(
            BankOnlyKind::Tarifa,
            BankOnlyMovement::query()->where('bank_transaction_id', $tx->id)->first()->kind,
        );
    }
}
