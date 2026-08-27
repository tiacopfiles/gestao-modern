<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

/**
 * Importação de extrato bancário pela interface web.
 *
 * A ingestão bancária da Fase 3 só existia em `/api/v1`, então a conciliação
 * pedia "importe fatos bancários antes de conciliar" sem oferecer caminho para
 * isso. Estes testes cobrem a tela que fecha esse fluxo, reutilizando o mesmo
 * `OfxImportService` da API.
 */
class BankStatementWebTest extends TestCase
{
    use RefreshesTestDatabase;

    private User $operator;

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

        $this->operator = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operator->id],
            'reconciliation.manage_user_ids' => [$this->operator->id],
        ]);
    }

    private function ofx(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/Banking/statement-valid.ofx'),
            'statement-valid.ofx',
            'application/x-ofx',
            null,
            true,
        );
    }

    public function test_screens_are_hidden_when_the_v2_flag_is_off(): void
    {
        config(['reconciliation.v2_enabled' => false]);

        $this->actingAs($this->operator)->get('/extratos')->assertNotFound();
        $this->actingAs($this->operator)->get('/extratos/importar')->assertNotFound();
    }

    public function test_screens_require_authentication(): void
    {
        $this->get('/extratos')->assertRedirect('/login');
        $this->get('/extratos/importar')->assertRedirect('/login');
    }

    public function test_import_requires_manage_permission_while_listing_requires_only_view(): void
    {
        config(['reconciliation.manage_user_ids' => []]);

        $this->actingAs($this->operator)->get('/extratos')->assertOk();
        $this->actingAs($this->operator)->get('/extratos/importar')->assertForbidden();
        $this->actingAs($this->operator)
            ->post('/extratos/importar', ['account_id' => 1, 'file' => $this->ofx()])
            ->assertForbidden();

        $this->assertSame(0, BankTransaction::query()->count());
    }

    public function test_ofx_upload_creates_transactions_and_an_auditable_batch(): void
    {
        $response = $this->actingAs($this->operator)
            ->post('/extratos/importar', ['account_id' => 1, 'file' => $this->ofx()]);

        $batch = ImportBatch::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('banking.batches.show', $batch));

        // Uma linha por FITID do extrato, nunca mais.
        $expected = preg_match_all('/<FITID>/', (string) file_get_contents(base_path('tests/Fixtures/Banking/statement-valid.ofx')));
        $this->assertSame($expected, BankTransaction::query()->count());
        $this->assertSame($expected, (int) $batch->imported_items);
        $this->assertSame('OFX', $batch->format);
        $this->assertSame(1, (int) $batch->account_id);

        // A origem do lote fica registrada como importação manual, não como
        // integração externa — é isso que a auditoria precisa distinguir.
        $this->assertNotNull($batch->integration_client_id);
        $this->assertSame('Importação manual (interface web)', $batch->integrationClient->name);
        $this->assertFalse((bool) $batch->integrationClient->active);

        $this->actingAs($this->operator)->get(route('banking.batches.show', $batch))->assertOk();
        $this->actingAs($this->operator)->get('/extratos')->assertOk()->assertSee('FIT-CREDIT-001');
    }

    public function test_reimporting_the_same_file_does_not_duplicate_facts(): void
    {
        $this->actingAs($this->operator)->post('/extratos/importar', ['account_id' => 1, 'file' => $this->ofx()]);
        $countAfterFirst = BankTransaction::query()->count();

        $this->actingAs($this->operator)->post('/extratos/importar', ['account_id' => 1, 'file' => $this->ofx()]);

        $this->assertSame($countAfterFirst, BankTransaction::query()->count());
        $this->assertSame(1, ImportBatch::query()->where('format', 'OFX')->count());
    }

    public function test_invalid_payload_is_rejected_with_readable_messages(): void
    {
        $this->actingAs($this->operator)
            ->post('/extratos/importar', ['account_id' => 1])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->operator)
            ->post('/extratos/importar', ['account_id' => 999999, 'file' => $this->ofx()])
            ->assertSessionHasErrors('account_id');

        $this->assertSame(0, BankTransaction::query()->count());
    }

    public function test_validation_messages_are_translated_instead_of_raw_keys(): void
    {
        $this->actingAs($this->operator)->post('/extratos/importar', ['account_id' => 1]);

        $message = (string) session('errors')->first('file');
        $this->assertStringNotContainsString('validation.', $message);
        $this->assertSame('O campo arquivo é obrigatório.', $message);
    }

    public function test_listing_filters_by_account_direction_and_term(): void
    {
        $this->actingAs($this->operator)->post('/extratos/importar', ['account_id' => 1, 'file' => $this->ofx()]);

        $this->actingAs($this->operator)->get('/extratos?direction=CREDIT')
            ->assertOk()->assertSee('FIT-CREDIT-001')->assertDontSee('FIT-DEBIT-001');
        $this->actingAs($this->operator)->get('/extratos?q=FIT-DEBIT-001')
            ->assertOk()->assertSee('FIT-DEBIT-001')->assertDontSee('FIT-CREDIT-001');
        $this->actingAs($this->operator)->get('/extratos?account_id=999')
            ->assertOk()->assertSee('Nenhum fato bancário');
    }
}
