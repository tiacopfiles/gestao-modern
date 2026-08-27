<?php

namespace App\Console\Commands;

use App\Application\Financial\PeriodStatementService;
use App\Domain\Financial\Money;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\PeriodStatement;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Cria no sistema as conciliações que hoje existem como abas de planilha.
 *
 * Cada aba das planilhas do Itaú é um mês de uma conta bancária, e começa com
 * "Saldo em DD/MM/AAAA" — o saldo inicial. É esse número que entra aqui: o
 * saldo de abertura não é calculado nem adivinhado, é o que o banco disse, e
 * digitá-lo errado faz o extrato inteiro fechar errado.
 *
 * O que o sistema NÃO copia é o saldo final. Ele é recalculado a partir das
 * liquidações e dos movimentos manuais que o sistema realmente tem. A diferença
 * entre esse número e o da planilha é o relatório mais útil deste comando: ela
 * mede, mês a mês, o quanto o sistema ainda não sabe. Copiar o fechamento da
 * planilha esconderia exatamente aquilo que precisa aparecer — a planilha é
 * gabarito, não fonte.
 *
 * Idempotente: uma conciliação já existente para a mesma conta bancária e o
 * mesmo período é reportada e pulada, nunca recriada.
 */
class CreateStatementsFromSpreadsheet extends Command
{
    protected $signature = 'gestao:criar-conciliacoes-planilha
        {arquivo : JSON com empresa, mes, abertura e fechamento de cada aba}
        {--dry-run : Só relata o que faria}
        {--empresa= : Limita a uma empresa}
        {--ano=2026 : Ano das abas}
        {--ator= : Id do usuário a registrar como autor}';

    protected $description = 'Cria as conciliações mensais a partir dos saldos das planilhas e compara o fechamento';

    public function __construct(private readonly PeriodStatementService $statements)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $arquivo = (string) $this->argument('arquivo');

        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $abas = json_decode((string) file_get_contents($arquivo), true);

        if (! is_array($abas)) {
            $this->error('JSON inválido.');

            return self::FAILURE;
        }

        $seco = (bool) $this->option('dry-run');
        $filtro = (string) ($this->option('empresa') ?? '');
        $ano = (int) $this->option('ano');
        $ator = $this->option('ator') !== null ? (int) $this->option('ator') : null;

        $this->info($seco ? 'MODO SECO — nada será gravado.' : 'CRIANDO conciliações.');
        $this->newLine();

        $cache = [];
        $linhas = [];
        $criadas = 0;
        $puladas = 0;

        foreach ($abas as $aba) {
            $nome = (string) ($aba['empresa'] ?? '');

            if ($filtro !== '' && mb_strtolower($nome) !== mb_strtolower($filtro)) {
                continue;
            }

            if (! array_key_exists($nome, $cache)) {
                $cache[$nome] = $this->resolver($nome);
            }

            if ($cache[$nome] === null) {
                continue;
            }

            [$conta, $banco] = $cache[$nome];

            if ($aba['abertura'] === null) {
                $this->warn(sprintf('%s %s: sem saldo inicial na planilha — pulada.', $nome, $aba['aba']));

                continue;
            }

            $inicio = Carbon::create($ano, (int) $aba['mes'], 1)->startOfMonth();
            $fim = $inicio->copy()->endOfMonth();

            $existente = PeriodStatement::query()
                ->where('account_id', $conta->id)
                ->where('bank_account_id', $banco?->id)
                ->whereDate('period_start', $inicio->toDateString())
                ->whereDate('period_end', $fim->toDateString())
                ->first();

            if ($existente !== null) {
                $puladas++;
                $linhas[] = $this->comparar($nome, $aba, $existente->closing_balance_cents, 'já existia');

                continue;
            }

            $aberturaCents = (int) round((float) $aba['abertura'] * 100);

            if ($seco) {
                $previa = $this->statements->preview(
                    (int) $conta->id,
                    $inicio->toDateString(),
                    $fim->toDateString(),
                    $aberturaCents,
                    $banco?->id,
                );
                $criadas++;
                $linhas[] = $this->comparar($nome, $aba, $previa['closing_cents'], 'prévia');

                continue;
            }

            try {
                $statement = $this->statements->create(
                    accountId: (int) $conta->id,
                    from: $inicio->toDateString(),
                    to: $fim->toDateString(),
                    openingCents: $aberturaCents,
                    actorId: $ator,
                    bankAccountId: $banco?->id,
                );
                $criadas++;
                $linhas[] = $this->comparar($nome, $aba, $statement->closing_balance_cents, 'criada');
            } catch (Throwable $e) {
                $this->warn(sprintf('%s %s: %s', $nome, $aba['aba'], $e->getMessage()));
            }
        }

        $this->table(
            ['Empresa', 'Mês', 'Abertura', 'Fecha (sistema)', 'Fecha (planilha)', 'Diferença', ''],
            $linhas,
        );

        $this->newLine();
        $this->info(sprintf('%d conciliações, %d já existiam.', $criadas, $puladas));
        $this->line('A coluna Diferença é o quanto o sistema ainda não sabe daquele mês —');
        $this->line('normalmente títulos que estão na planilha e não chegaram pelas origens.');

        return self::SUCCESS;
    }

    /** @return array{0: Conta, 1: BankAccount|null}|null */
    private function resolver(string $nome): ?array
    {
        $conta = Conta::query()->whereRaw('LOWER(nome) = ?', [mb_strtolower(trim($nome))])->first();

        if ($conta === null) {
            $this->warn("Empresa \"{$nome}\" não encontrada em `contas` — pulada.");

            return null;
        }

        $banco = BankAccount::padraoDaEmpresa((int) $conta->id);

        if ($banco === null) {
            $this->warn("Empresa \"{$nome}\" não tem conta bancária padrão.");
        }

        return [$conta, $banco];
    }

    /** @return list<string> */
    private function comparar(string $empresa, array $aba, int $sistemaCents, string $situacao): array
    {
        $planilhaCents = $aba['fechamento'] === null ? null : (int) round((float) $aba['fechamento'] * 100);
        $diferenca = $planilhaCents === null ? null : $sistemaCents - $planilhaCents;

        return [
            $empresa,
            (string) $aba['aba'],
            $this->brl((int) round((float) $aba['abertura'] * 100)),
            $this->brl($sistemaCents),
            $planilhaCents === null ? '—' : $this->brl($planilhaCents),
            $diferenca === null ? '—' : $this->brl($diferenca),
            $situacao,
        ];
    }

    private function brl(int $cents): string
    {
        return ($cents < 0 ? '-' : '').number_format((float) Money::fromCents(abs($cents)), 2, ',', '.');
    }
}
