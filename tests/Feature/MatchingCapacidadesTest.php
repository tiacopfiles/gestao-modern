<?php

namespace Tests\Feature;

use App\Application\Financial\TitleIngestionService;
use App\Application\Reconciliation\ManualReconciliationService;
use App\Application\Reconciliation\ReconciliationSessionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Money;
use App\Domain\Financial\TitleIngestionData;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Models\BankTransaction;
use App\Models\Conta;
use App\Models\FinancialTitle;
use App\Models\ImportBatch;
use App\Models\ReconciliationSession;
use App\Models\SourceSystem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CreatesLegacyWitnessTables;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

/**
 * O que o motor de conciliação precisa suportar para reproduzir as planilhas.
 *
 * As três conciliações reais (Acop Files, Duemagem, Global Box) mostram que o
 * extrato bancário e os títulos não se correspondem um a um. O caso obrigatório
 * é o título 85320 — "Salários 01/2026", R$ 14.748,88 na origem — que a planilha
 * da Acop Files quebra em oito saídas separadas, uma por forma de pagamento.
 *
 * Os valores abaixo são os reais; as transações bancárias são sintéticas e vivem
 * só nesta suíte. Nada disso é gravado no sistema: fabricar fato bancário para
 * fazer total fechar destruiria justamente o que a conciliação serve para achar.
 */
class MatchingCapacidadesTest extends TestCase
{
    use CreatesLegacyWitnessTables;
    use RefreshesTestDatabase;

    private User $operator;

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
        $this->createLegacyWitnessTables();

        Conta::query()->create(['id' => 1, 'nome' => 'Acop Files']);

        $this->operator = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operator->id],
            'reconciliation.manage_user_ids' => [$this->operator->id],
        ]);
    }

    private function sessao(): ReconciliationSession
    {
        return app(ReconciliationSessionService::class)->create(
            1, '2026-01-01', '2026-01-31', $this->operator->id, (string) Str::uuid(),
        );
    }

    private function titulo(FinancialTitleType $tipo, string $valor, string $nome = 'Parte'): FinancialTitle
    {
        $id = ++self::$seq;

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API',
            externalId: "CAP-{$id}",
            type: $tipo,
            issueDate: '2025-12-01',
            dueDate: '2026-01-20',
            originalAmount: $valor,
            partyName: $nome,
            documentNumber: "DOC-{$id}",
            accountId: 1,
            installmentCount: 1,
        ), $this->operator->id)->title->load('installments');
    }

    private function transacao(string $direcao, string $valor, string $descricao = 'movimento'): BankTransaction
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
            'external_id' => "CAP-TX-{$id}", 'identity_quality' => 'STRONG',
            'direction' => $direcao, 'amount' => $valor, 'currency' => 'BRL',
            'transaction_date' => '2026-01-20', 'description_original' => $descricao,
            'payload_hash' => hash('sha256', "p{$id}"), 'raw_hash' => hash('sha256', "r{$id}"),
        ]);
    }

    /**
     * @param  list<array{FinancialTitle, string}>  $titulos
     * @param  list<array{BankTransaction, string}>  $transacoes
     */
    private function conciliar(ReconciliationSession $s, array $titulos, array $transacoes)
    {
        return app(ManualReconciliationService::class)->confirm(
            $s->id,
            array_map(fn (array $t) => new ReconciliationTitleAllocationData(
                $t[0]->id, $t[0]->installments->first()?->id, $t[1],
            ), $titulos),
            array_map(fn (array $x) => new ReconciliationTransactionAllocationData($x[0]->id, $x[1]), $transacoes),
            $this->operator->id,
            (string) Str::uuid(),
        );
    }

    public function test_1_titulo_para_1_transacao(): void
    {
        $s = $this->sessao();
        $t = $this->titulo(FinancialTitleType::Payable, '592.31', 'Embalimp');
        $x = $this->transacao('DEBIT', '592.31', 'V.06/01 Embalimp NF.237058');

        $match = $this->conciliar($s, [[$t, '592.31']], [[$x, '592.31']]);

        $this->assertSame('CONFIRMED', $match->status->value);
        $this->assertCount(1, $match->titleAllocations);
        $this->assertCount(1, $match->transactionAllocations);
    }

    /**
     * O CASO OBRIGATÓRIO. Título 85320 "Salários 01/2026", R$ 14.748,88, que a
     * planilha da Acop Files quebra em oito saídas — cartão, poupança e outros
     * bancos, uma linha por funcionário.
     */
    public function test_1_titulo_para_n_transacoes_caso_85320_salarios(): void
    {
        $s = $this->sessao();
        $salarios = $this->titulo(FinancialTitleType::Payable, '14748.88', 'Salários');

        // As oito saídas, somando exatamente o título.
        $valores = ['6714.00', '1618.00', '1018.00', '1018.00', '1120.00', '1018.00', '937.00', '1305.88'];
        $this->assertSame(
            1474888,
            array_sum(array_map(fn (string $v): int => Money::toCents($v), $valores)),
            'as parcelas de teste precisam somar o título real',
        );

        $transacoes = [];
        foreach ($valores as $i => $v) {
            $transacoes[] = [$this->transacao('DEBIT', $v, "Adiant.Salarial 01/2026 #{$i}"), $v];
        }

        $match = $this->conciliar($s, [[$salarios, '14748.88']], $transacoes);

        $this->assertSame('CONFIRMED', $match->status->value);
        $this->assertCount(8, $match->transactionAllocations);
        $this->assertSame(
            1474888,
            (int) $match->transactionAllocations->sum(fn ($a): int => Money::toCents((string) $a->allocated_amount)),
        );
    }

    /**
     * O inverso: um crédito único do banco pagando vários títulos. É o padrão
     * das notas da Faculdade de Medicina, depositadas em bloco.
     */
    public function test_n_titulos_para_1_transacao(): void
    {
        $s = $this->sessao();
        $a = $this->titulo(FinancialTitleType::Receivable, '685.14', 'Fac.Medicina Casa da Aids');
        $b = $this->titulo(FinancialTitleType::Receivable, '243.39', 'Fac.Medicina CSESBP');
        $c = $this->titulo(FinancialTitleType::Receivable, '208.00', 'Fac.Medicina Derma');
        $deposito = $this->transacao('CREDIT', '1136.53', 'Deposito Fac.Medicina');

        $match = $this->conciliar($s, [[$a, '685.14'], [$b, '243.39'], [$c, '208.00']], [[$deposito, '1136.53']]);

        $this->assertSame('CONFIRMED', $match->status->value);
        $this->assertCount(3, $match->titleAllocations);
        $this->assertCount(1, $match->transactionAllocations);
    }

    public function test_liquidacao_parcial_deixa_o_titulo_em_aberto(): void
    {
        $s = $this->sessao();
        $t = $this->titulo(FinancialTitleType::Receivable, '1906.99', 'Fundação Rio Claro');
        $parcial = $this->transacao('CREDIT', '1500.00', 'Deposito parcial');

        $match = $this->conciliar($s, [[$t, '1500.00']], [[$parcial, '1500.00']]);

        $this->assertSame('CONFIRMED', $match->status->value);
        $this->assertSame(150000, (int) Money::toCents((string) $match->titleAllocations->first()->allocated_amount));
        // O saldo do título continua aberto: conciliar não liquida.
        $this->assertGreaterThan(0, $t->fresh()->remainingCents());
    }

    /**
     * Somas diferentes dos dois lados não podem virar match: seria declarar
     * explicado o que não está, e o valor sumiria da lista de pendências.
     */
    public function test_diferenca_entre_os_lados_e_recusada(): void
    {
        $s = $this->sessao();
        $t = $this->titulo(FinancialTitleType::Payable, '1000.00');
        $x = $this->transacao('DEBIT', '900.00');

        $this->expectException(ReconciliationRuleViolation::class);
        $this->conciliar($s, [[$t, '1000.00']], [[$x, '900.00']]);
    }

    /**
     * Movimento só bancário: tarifa, rendimento e transferência entre contas
     * existem no extrato e não têm título nenhum — juntos são R$ 230.151,24 num
     * único mês da Acop Files. Precisam poder existir sem match.
     */
    public function test_movimento_so_bancario_existe_sem_titulo(): void
    {
        $tarifa = $this->transacao('DEBIT', '75.62', 'TAR Cobranca EXP');
        $rendimento = $this->transacao('CREDIT', '0.09', 'Rend Pago Aplic Aut APR');
        $transferencia = $this->transacao('DEBIT', '100000.00', 'Transferência Itaú ag 9697 c/c 07155-4');

        foreach ([$tarifa, $rendimento, $transferencia] as $tx) {
            $this->assertNotNull($tx->fresh(), 'o movimento bancário precisa existir por si só');
            $this->assertSame(0, $tx->reconciliationAllocations()->count(), 'não pode nascer conciliado com nada');
        }
    }
}
