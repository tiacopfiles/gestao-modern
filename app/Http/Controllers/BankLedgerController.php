<?php

namespace App\Http\Controllers;

use App\Application\Banking\BankLedgerService;
use App\Domain\Financial\Money;
use App\Models\Conta;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Extrato operacional: quanto tinha, quanto entrou, quanto saiu, quanto ficou.
 *
 * É a visão que o sistema antigo tinha e que o núcleo moderno ainda não
 * oferecia — nele existiam listas de títulos, de transações e de matches, mas
 * nenhuma tela respondia "qual era o saldo depois deste movimento".
 */
class BankLedgerController extends Controller
{
    public function __construct(private readonly BankLedgerService $ledger) {}

    public function index(Request $request): View
    {
        $accounts = Conta::query()->orderBy('nome')->get();
        $accountId = (int) ($request->input('account_id') ?: $accounts->first()?->id ?: 0);

        [$from, $to] = $this->period($request);
        $openingCents = $this->openingCents($request);

        $data = $accountId > 0
            ? $this->ledger->build(
                accountId: $accountId,
                from: $from,
                to: $to,
                openingCents: $openingCents,
                direction: in_array($request->input('direction'), ['CREDIT', 'DEBIT'], true) ? $request->input('direction') : null,
                reconciled: in_array($request->input('reconciled'), ['yes', 'no'], true) ? $request->input('reconciled') : null,
                term: $request->input('q'),
            )
            : ['lines' => [], 'opening_cents' => $openingCents, 'closing_cents' => $openingCents,
                'credits_cents' => 0, 'debits_cents' => 0, 'reconciled_cents' => 0,
                'unreconciled_cents' => 0, 'count' => 0];

        $account = Conta::query()->find($accountId);

        return view('ledger.index', compact('data', 'accounts', 'account', 'accountId', 'from', 'to'));
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $accountId = (int) $request->input('account_id');
        $data = $this->ledger->build($accountId, $from, $to, $this->openingCents($request));

        $filename = "extrato-conta-{$accountId}-{$from}-a-{$to}.csv";

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Data', 'Documento', 'Histórico', 'Origem', 'Entrada', 'Saída', 'Saldo', 'Conciliação', 'Títulos'], ';');
            fputcsv($out, ['', '', 'SALDO INICIAL', '', '', '', Money::fromCents($data['opening_cents']), '', ''], ';');
            foreach ($data['lines'] as $line) {
                fputcsv($out, [
                    $line['date']->format('d/m/Y'),
                    $line['document'],
                    $line['description'],
                    $line['origin'],
                    $line['credit_cents'] > 0 ? Money::fromCents($line['credit_cents']) : '',
                    $line['debit_cents'] > 0 ? Money::fromCents($line['debit_cents']) : '',
                    Money::fromCents($line['balance_cents']),
                    $line['status'],
                    implode(', ', $line['titles']),
                ], ';');
            }
            fputcsv($out, ['', '', 'SALDO FINAL', '', Money::fromCents($data['credits_cents']),
                Money::fromCents($data['debits_cents']), Money::fromCents($data['closing_cents']), '', ''], ';');
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $today = CarbonImmutable::today();
        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->input('from'))
            : $today->startOfMonth();
        $to = $request->filled('to')
            ? CarbonImmutable::parse((string) $request->input('to'))
            : $today->endOfMonth();

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * Saldo inicial informado pelo operador.
     *
     * Aceita `1234,56` e `1234.56`. Não é saldo contábil oficial — o domínio
     * moderno ainda não tem essa noção, e inventá-la seria decidir uma regra
     * financeira que cabe ao financeiro (pergunta 9 da Fase 6).
     */
    private function openingCents(Request $request): int
    {
        $raw = trim((string) $request->input('opening_balance', ''));
        if ($raw === '') {
            return 0;
        }

        $normalized = str_replace(['.', ','], ['', '.'], $raw);
        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized)) {
            return 0;
        }

        $negative = str_starts_with($normalized, '-');
        $cents = Money::toCents(ltrim($normalized, '-'));

        return $negative ? -$cents : $cents;
    }
}
