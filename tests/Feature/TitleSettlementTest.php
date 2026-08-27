<?php

namespace Tests\Feature;

use App\Application\Financial\TitleIngestionService;
use App\Application\Integration\IntegrationCredentialService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Models\BankAccount;
use App\Models\FinancialTitle;
use App\Models\TitleSettlement;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

/**
 * Realização (liquidação) de título — pela API e pela interface.
 *
 * É o elo que faltava entre "a funcionária deu baixa no Contas a Pagar" e "o
 * Gestão sabe que o título foi pago". O `SettlementService` já existia, mas não
 * estava exposto em lugar nenhum: nem endpoint, nem tela.
 *
 * Regra de fronteira testada aqui: registrar realização **não** cria
 * conciliação. São conceitos diferentes (ADR-010) e continuam separados.
 */
class TitleSettlementTest extends TestCase
{
    use RefreshesTestDatabase;

    private User $operator;

    /**
     * A conta bancária por onde as baixas feitas PELA TELA saem.
     *
     * Ela é obrigatória neste caminho de propósito (ADR-017): quem está na
     * tela sabe por onde o dinheiro passou, e deixar em branco recriaria a
     * convenção da conta padrão que o ADR encerra. Só a sincronização com as
     * origens legadas pode gravar sem banco, porque `contas`/`contasareceber`
     * não têm a coluna.
     */
    private int $bankAccountId;

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
        DB::table('contas')->insert(['id' => 1, 'nome' => 'Conta sintética', 'created_at' => now(), 'updated_at' => now()]);

        $this->bankAccountId = (int) BankAccount::query()->create([
            'company_id' => 1, 'company_name' => 'Conta sintética',
            'bank_name' => 'Banco Sintético', 'bank_code' => '999',
            'agency' => '0001', 'number' => '12345-6',
            'active' => true, 'is_default' => true,
        ])->id;

        $this->operator = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'), 'pagamentos' => true,
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operator->id],
            'reconciliation.manage_user_ids' => [$this->operator->id],
        ]);
    }

    private function title(FinancialTitleType $type, string $amount, string $externalId, int $installments = 1): FinancialTitle
    {
        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API', externalId: $externalId, type: $type,
            issueDate: '2026-08-01', dueDate: '2026-08-20', originalAmount: $amount,
            partyName: 'Parte sintética', documentNumber: 'DOC-'.$externalId,
            accountId: 1, installmentCount: $installments,
        ), $this->operator->id)->title->load('installments');
    }

    // ---------------------------------------------------------------- API ---

    public function test_origin_can_report_a_payment_through_the_api(): void
    {
        $title = $this->title(FinancialTitleType::Payable, '1000.00', 'PAY-API-1');
        $issued = app(IntegrationCredentialService::class)->issue('API', 'Contas a Pagar', ['payables:write']);

        $response = $this->withToken($issued->plainTextToken)
            ->withHeaders(['Idempotency-Key' => 'settle-1'])
            ->postJson('/api/v1/payables/PAY-API-1/settlements', ['settlement_date' => '2026-08-18']);

        $response->assertCreated()
            ->assertJsonPath('meta.decision', 'SETTLED')
            ->assertJsonPath('meta.settlement.amount', '1000.00')
            ->assertJsonPath('meta.settlement.type', 'PAYMENT');

        $this->assertSame('SETTLED', $title->fresh()->status->value);
        $this->assertSame(0, $title->fresh()->remainingCents());
    }

    public function test_receivable_settlement_records_a_receipt(): void
    {
        $this->title(FinancialTitleType::Receivable, '500.00', 'REC-API-1');
        $issued = app(IntegrationCredentialService::class)->issue('API', 'Contas a Receber', ['receivables:write']);

        $this->withToken($issued->plainTextToken)
            ->withHeaders(['Idempotency-Key' => 'settle-rec-1'])
            ->postJson('/api/v1/receivables/REC-API-1/settlements', ['settlement_date' => '2026-08-18'])
            ->assertCreated()
            ->assertJsonPath('meta.settlement.type', 'RECEIPT');
    }

    public function test_partial_settlement_leaves_the_title_partially_settled(): void
    {
        $title = $this->title(FinancialTitleType::Payable, '1000.00', 'PAY-API-2');
        $issued = app(IntegrationCredentialService::class)->issue('API', 'Contas a Pagar', ['payables:write']);

        $this->withToken($issued->plainTextToken)
            ->withHeaders(['Idempotency-Key' => 'partial-1'])
            ->postJson('/api/v1/payables/PAY-API-2/settlements', [
                'settlement_date' => '2026-08-18', 'amount' => '400.00',
            ])->assertCreated();

        $title->refresh();
        $this->assertSame('PARTIALLY_SETTLED', $title->status->value);
        $this->assertSame(60000, $title->remainingCents());
    }

    public function test_resending_the_same_settlement_does_not_duplicate_it(): void
    {
        $this->title(FinancialTitleType::Payable, '250.00', 'PAY-API-3');
        $issued = app(IntegrationCredentialService::class)->issue('API', 'Contas a Pagar', ['payables:write']);

        $payload = ['settlement_date' => '2026-08-18', 'external_id' => 'BAIXA-LEGADO-99'];

        $this->withToken($issued->plainTextToken)->withHeaders(['Idempotency-Key' => 'dup-a'])
            ->postJson('/api/v1/payables/PAY-API-3/settlements', $payload)
            ->assertCreated()
            ->assertJsonPath('meta.decision', 'SETTLED');

        // Reenvio da MESMA baixa com outra chave HTTP — o caso real de retry por
        // timeout na origem. Precisa ser sucesso idempotente, nunca erro: o
        // saldo restante já é zero neste ponto, e uma implementação ingênua
        // recalcularia o valor e recusaria por conflito de payload.
        $this->withToken($issued->plainTextToken)->withHeaders(['Idempotency-Key' => 'dup-b'])
            ->postJson('/api/v1/payables/PAY-API-3/settlements', $payload)
            ->assertOk()
            ->assertJsonPath('meta.decision', 'ALREADY_SETTLED')
            ->assertJsonPath('meta.idempotency_replayed', true)
            ->assertJsonPath('meta.settlement.amount', '250.00');

        $this->assertSame(1, TitleSettlement::query()->count());
    }

    public function test_settlement_requires_scope_and_a_known_title(): void
    {
        $this->title(FinancialTitleType::Payable, '100.00', 'PAY-API-4');

        $this->postJson('/api/v1/payables/PAY-API-4/settlements', ['settlement_date' => '2026-08-18'])
            ->assertUnauthorized();

        $read = app(IntegrationCredentialService::class)->issue('API', 'Somente leitura', ['payables:read']);
        $this->withToken($read->plainTextToken)->withHeaders(['Idempotency-Key' => 'scope-1'])
            ->postJson('/api/v1/payables/PAY-API-4/settlements', ['settlement_date' => '2026-08-18'])
            ->assertForbidden();

        $write = app(IntegrationCredentialService::class)->issue('API', 'Escrita', ['payables:write']);
        $this->withToken($write->plainTextToken)->withHeaders(['Idempotency-Key' => 'missing-1'])
            ->postJson('/api/v1/payables/NAO-EXISTE/settlements', ['settlement_date' => '2026-08-18'])
            ->assertNotFound();
    }

    public function test_settlement_rejects_unknown_fields_and_overpayment(): void
    {
        $this->title(FinancialTitleType::Payable, '100.00', 'PAY-API-5');
        $issued = app(IntegrationCredentialService::class)->issue('API', 'Escrita', ['payables:write']);

        $this->withToken($issued->plainTextToken)->withHeaders(['Idempotency-Key' => 'bad-1'])
            ->postJson('/api/v1/payables/PAY-API-5/settlements', [
                'settlement_date' => '2026-08-18', 'status' => 'SETTLED',
            ])->assertUnprocessable();

        // Pagar mais do que o título deve é conflito com o estado atual (409),
        // seguindo o mesmo padrão dos demais conflitos de domínio da API v1.
        $this->withToken($issued->plainTextToken)->withHeaders(['Idempotency-Key' => 'bad-2'])
            ->postJson('/api/v1/payables/PAY-API-5/settlements', [
                'settlement_date' => '2026-08-18', 'amount' => '999.00',
            ])->assertStatus(409)
            ->assertJsonPath('error.code', 'DOMAIN_CONFLICT');

        $this->assertSame(0, TitleSettlement::query()->count());
    }

    // ----------------------------------------------------------- Interface ---

    public function test_web_quick_action_marks_a_title_as_paid(): void
    {
        $title = $this->title(FinancialTitleType::Payable, '750.00', 'PAY-WEB-1');

        $this->actingAs($this->operator)
            ->post(route('titles.settle', $title), [
                'settlement_date' => '2026-08-18',
                'bank_account_id' => $this->bankAccountId,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $title->refresh();
        $this->assertSame('SETTLED', $title->status->value);
        $this->assertSame(1, $title->settlements()->count());
        $this->assertSame($this->operator->id, (int) $title->settlements()->first()->created_by);
    }

    public function test_settlement_does_not_create_any_reconciliation(): void
    {
        $title = $this->title(FinancialTitleType::Payable, '300.00', 'PAY-WEB-2');

        $this->actingAs($this->operator)
            ->post(route('titles.settle', $title), [
                'settlement_date' => '2026-08-18',
                'bank_account_id' => $this->bankAccountId,
            ])
            ->assertSessionHasNoErrors();

        // Realizar e conciliar são coisas diferentes: marcar como pago nunca
        // pode, sozinho, produzir um match.
        $this->assertSame(0, DB::table('reconciliation_matches')->count());
        $this->assertSame(0, DB::table('reconciliation_match_titles')->count());
    }

    public function test_quick_action_requires_the_payments_permission(): void
    {
        $title = $this->title(FinancialTitleType::Payable, '120.00', 'PAY-WEB-3');
        $viewer = User::query()->create([
            'nome' => 'Somente leitura', 'username' => 'leitor', 'password' => bcrypt('secret'), 'pagamentos' => false,
        ]);

        $this->actingAs($viewer)
            ->post(route('titles.settle', $title), ['settlement_date' => '2026-08-18'])
            ->assertForbidden();

        $this->assertSame('OPEN', $title->fresh()->status->value);
    }

    /**
     * A baixa rápida da lista precisa perguntar o banco, não arbitrá-lo.
     *
     * `settle()` passou a exigir `bank_account_id` (ADR-017) e o formulário da
     * lista não mandava o campo — o botão respondia com redirect, a validação
     * recusava e o título continuava em aberto. Da tela, quem responde por
     * onde o dinheiro passou é a pessoa.
     */
    public function test_a_baixa_rapida_da_lista_oferece_a_escolha_da_conta_bancaria(): void
    {
        $this->title(FinancialTitleType::Payable, '150.00', 'LISTA-COM-BANCO');

        $this->actingAs($this->operator)->get('/titulos?type=PAYABLE')
            ->assertOk()
            ->assertSee('name="bank_account_id"', false)
            ->assertSee('Banco Sintético 0001/12345-6')
            ->assertSee('Marcar como pago');
    }

    public function test_sem_conta_bancaria_a_lista_nao_oferece_um_botao_que_falharia(): void
    {
        // Empresa sem conta bancária: a baixa seria recusada pela validação,
        // então a linha explica em vez de oferecer o botão.
        DB::table('contas')->insert(['id' => 2, 'nome' => 'Empresa sem banco', 'created_at' => now(), 'updated_at' => now()]);
        app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API', externalId: 'LISTA-SEM-BANCO', type: FinancialTitleType::Payable,
            issueDate: '2026-08-01', dueDate: '2026-08-20', originalAmount: '90.00',
            partyName: 'Parte sintética', documentNumber: 'DOC-LISTA-SEM-BANCO',
            accountId: 2, installmentCount: 1,
        ), $this->operator->id);

        $this->actingAs($this->operator)->get('/titulos?type=PAYABLE&account_id=2')
            ->assertOk()
            ->assertSee('LISTA-SEM-BANCO')
            ->assertSee('Sem banco cadastrado')
            ->assertDontSee('Marcar como pago');
    }

    public function test_titles_screen_lists_and_filters(): void
    {
        $this->title(FinancialTitleType::Payable, '111.00', 'LIST-PAY');
        $this->title(FinancialTitleType::Receivable, '222.00', 'LIST-REC');

        $this->actingAs($this->operator)->get('/titulos')
            ->assertOk()->assertSee('LIST-PAY')->assertSee('LIST-REC');

        $this->actingAs($this->operator)->get('/titulos?type=PAYABLE')
            ->assertOk()->assertSee('LIST-PAY')->assertDontSee('LIST-REC');

        $this->actingAs($this->operator)->get('/titulos?q=LIST-REC')
            ->assertOk()->assertSee('LIST-REC')->assertDontSee('LIST-PAY');
    }

    /**
     * A tela abria filtrada em "Em aberto" para que o que estava pago não
     * empurrasse o trabalho do dia para baixo. Quem ordena isso agora é a ordem
     * da lista (a vencer → vencido → baixado), então esconder deixou de ser
     * necessário: o pago volta a aparecer, no fim. Ver OrdemDaListaDeTitulosTest.
     */
    public function test_titles_screen_shows_every_status_but_lets_the_user_pick_one(): void
    {
        $this->title(FinancialTitleType::Payable, '150.00', 'DEFAULT-OPEN');
        $settled = $this->title(FinancialTitleType::Payable, '200.00', 'DEFAULT-SETTLED');

        $this->actingAs($this->operator)
            ->post(route('titles.settle', $settled), [
                'settlement_date' => '2026-08-18',
                'bank_account_id' => $this->bankAccountId,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Entrando sem escolher situação: aberto e baixado convivem na tela.
        $this->actingAs($this->operator)->get('/titulos?type=PAYABLE')
            ->assertOk()->assertSee('DEFAULT-OPEN')->assertSee('DEFAULT-SETTLED');

        // "Todas" explicitamente (status=vazio, o que o select manda) é o mesmo.
        $this->actingAs($this->operator)->get('/titulos?type=PAYABLE&status=')
            ->assertOk()->assertSee('DEFAULT-OPEN')->assertSee('DEFAULT-SETTLED');

        // Escolher uma situação continua estreitando a lista.
        $this->actingAs($this->operator)->get('/titulos?type=PAYABLE&status=OPEN')
            ->assertOk()->assertSee('DEFAULT-OPEN')->assertDontSee('DEFAULT-SETTLED');

        $this->actingAs($this->operator)->get('/titulos?type=PAYABLE&status=SETTLED')
            ->assertOk()->assertDontSee('DEFAULT-OPEN')->assertSee('DEFAULT-SETTLED');
    }

    public function test_titles_screen_is_hidden_when_the_flag_is_off(): void
    {
        config(['reconciliation.v2_enabled' => false]);

        $this->actingAs($this->operator)->get('/titulos')->assertNotFound();
    }

    public function test_totals_cover_the_whole_filter_not_just_the_visible_page(): void
    {
        // 30 títulos de R$ 100,00 — mais que os 25 de uma página.
        //
        // Este caso existe porque `paginate()` altera o próprio builder: clonar a
        // consulta depois dele traz apenas a página corrente, e o rodapé passava
        // a somar 25 linhas em vez do filtro inteiro. Com poucos registros o erro
        // é invisível; só aparece quando os dados passam de uma página.
        for ($i = 1; $i <= 30; $i++) {
            $this->title(FinancialTitleType::Payable, '100.00', 'PAGE-'.$i);
        }

        $response = $this->actingAs($this->operator)->get('/titulos?type=PAYABLE');

        $response->assertOk()
            ->assertSee('3.000,00')      // 30 × R$ 100,00, e não 25 × R$ 100,00
            ->assertDontSee('2.500,00');
    }
}
