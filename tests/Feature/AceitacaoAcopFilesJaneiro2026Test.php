<?php

namespace Tests\Feature;

use App\Application\Reconciliation\BankOnlyMovementService;
use App\Domain\Financial\Money;
use App\Domain\Reconciliation\Enums\BankOnlyKind;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Conta;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

/**
 * ACEITAÇÃO — Acop Files, janeiro/2026, Banco Itaú Ag 6260 C/C 13377-9.
 *
 * O gabarito é a conciliação que o financeiro já faz à mão, em
 * `K:\GERAL\TI\Conciliação Itaú Acop Files.xlsx`, aba `Janeiro-2026`:
 *
 *     Saldo inicial   R$ 107.111,97
 *     Entradas        R$ 365.332,23
 *     Saídas          R$ 425.424,02
 *     Saldo final     R$  47.020,18
 *
 * São 80 meses conferidos por gente ao longo de seis anos. Isso torna a planilha
 * um critério melhor que qualquer número que o próprio sistema produza: se ele
 * reproduzir o mês linha a linha, está certo; se não, o erro aparece na linha.
 *
 * O QUE ESTE ARQUIVO AINDA NÃO FAZ, E POR QUE
 * ------------------------------------------
 * A comparação linha a linha depende do OFX real do Itaú, que ainda não existe.
 * E ele não pode ser substituído pela própria planilha: a planilha é o gabarito,
 * não a fonte do banco. Gerar `bank_transactions` a partir dela faria o sistema
 * conferir consigo mesmo e o teste passaria sem provar nada — exatamente o erro
 * que a conciliação existe para impedir.
 *
 * Então aqui ficam gravados os números esperados e as regras de leitura. Quando
 * o OFX chegar, `test_conciliacao_completa_reproduz_a_planilha` sai de skipped e
 * passa a valer: OFX → bank_transactions → matching → classificação do que é só
 * bancário → saldo final R$ 47.020,18.
 */
class AceitacaoAcopFilesJaneiro2026Test extends TestCase
{
    use RefreshesTestDatabase;

    // Gabarito, em centavos.
    private const SALDO_INICIAL = 10711197;   // R$ 107.111,97 em 31/12/2025

    private const ENTRADAS = 36533223;        // R$ 365.332,23

    private const SAIDAS = 42542402;          // R$ 425.424,02

    private const SALDO_FINAL = 4702018;      // R$  47.020,18

    private const MOVIMENTOS = 136;

    // Como as 136 linhas se dividem, apurado da própria planilha.
    private const LINHAS_COM_ID = 64;         // vêm de Contas a Pagar

    private const LINHAS_COM_NF = 53;         // vêm de Contas a Receber

    private const LINHAS_SO_BANCO = 19;       // R$ 230.151,24 sem título nenhum

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome');
            $table->string('banco', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_o_gabarito_fecha_por_dentro(): void
    {
        $this->assertSame(
            self::SALDO_FINAL,
            self::SALDO_INICIAL + self::ENTRADAS - self::SAIDAS,
            'o gabarito da planilha não fecha: conferir a aba antes de usar como critério',
        );
    }

    public function test_as_linhas_do_gabarito_somam_o_total_de_movimentos(): void
    {
        $this->assertSame(
            self::MOVIMENTOS,
            self::LINHAS_COM_ID + self::LINHAS_COM_NF + self::LINHAS_SO_BANCO,
        );
    }

    public function test_a_conta_bancaria_do_gabarito_esta_modelada(): void
    {
        $empresa = Conta::query()->create(['nome' => 'Acop Files']);

        $conta = BankAccount::query()->create([
            'company_id' => $empresa->id,
            'company_name' => $empresa->nome,
            'bank_name' => 'Banco Itaú',
            'bank_code' => '341',
            'agency' => '6260',
            'number' => '13377-9',
            'label' => 'Banco Itaú - Agência 6260 - C/C 13377-9',
        ]);

        $this->assertSame('Banco Itaú - Agência 6260 - C/C 13377-9', $conta->fullLabel());
        $this->assertSame($empresa->id, $conta->company_id);
    }

    /**
     * As 19 linhas sem título da planilha, agrupadas. Se o classificador não
     * reconhecer esses textos, elas viram pendência eterna na conciliação real.
     */
    #[DataProvider('movimentosSoBancariosDoGabarito')]
    public function test_o_classificador_reconhece_os_movimentos_so_bancarios_do_mes(
        string $texto,
        BankOnlyKind $esperado,
    ): void {
        $this->assertSame(
            $esperado,
            app(BankOnlyMovementService::class)->suggest($texto),
            "o classificador não reconheceu \"{$texto}\", que aparece na planilha de janeiro",
        );
    }

    public static function movimentosSoBancariosDoGabarito(): array
    {
        return [
            // 3 linhas, R$ 230.000,00 — mais da metade das saídas do mês
            'transferencia para Marco Antonio Colitti' => [
                'Transferência Itaú agência 9697 c/c 07155-4 Sr.Marco Antonio Colitti',
                BankOnlyKind::TransferenciaInterna,
            ],
            // 14 linhas, R$ 12,97
            'rendimento da aplicacao automatica' => ['Rend Pago Aplic Aut APR', BankOnlyKind::Rendimento],
            // 2 linhas, R$ 151,24
            'tarifa de cobranca' => ['TAR Cobrança EXP', BankOnlyKind::Tarifa],
        ];
    }

    /**
     * O teste que fecha tudo. Fica marcado como incompleto até o OFX existir —
     * incompleto, e não passando, porque um verde aqui sem o arquivo do banco
     * seria mentira.
     */
    public function test_conciliacao_completa_reproduz_a_planilha(): void
    {
        $temExtrato = BankTransaction::query()
            ->whereBetween('transaction_date', ['2026-01-01', '2026-01-31'])
            ->exists();

        if (! $temExtrato) {
            $this->markTestIncomplete(
                'Aguardando o OFX real do Itaú Ag 6260 C/C 13377-9 para 01/12/2025 a 31/01/2026. '
                .'A planilha é gabarito, não fonte: gerar bank_transactions a partir dela faria o '
                .'sistema conferir consigo mesmo.'
            );
        }

        // A partir daqui só roda com extrato real importado.
        $entradas = (int) BankTransaction::query()
            ->whereBetween('transaction_date', ['2026-01-01', '2026-01-31'])
            ->where('direction', 'CREDIT')
            ->get()
            ->sum(fn (BankTransaction $t): int => Money::toCents((string) $t->amount));

        $saidas = (int) BankTransaction::query()
            ->whereBetween('transaction_date', ['2026-01-01', '2026-01-31'])
            ->where('direction', 'DEBIT')
            ->get()
            ->sum(fn (BankTransaction $t): int => Money::toCents((string) $t->amount));

        $this->assertSame(self::ENTRADAS, $entradas, 'as entradas do extrato não batem com a planilha');
        $this->assertSame(self::SAIDAS, $saidas, 'as saídas do extrato não batem com a planilha');
        $this->assertSame(
            self::SALDO_FINAL,
            self::SALDO_INICIAL + $entradas - $saidas,
            'o saldo final não fecha em R$ 47.020,18',
        );
    }
}
