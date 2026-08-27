<?php

namespace App\Http\Controllers;

use App\Models\Conta;
use App\Models\Lancamento;
use App\Models\Movimento;
use App\Models\Recebimento;
use Illuminate\Http\Request;

class CashFlowController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->endOfMonth()->toDateString();
        $account = (string) $request->query('account', '');
        $rows = collect();
        $pay = Lancamento::whereBetween('data_vencimento', [$from, $to]);
        $rec = Recebimento::whereBetween('data_vencimento', [$from, $to]);
        $mov = Movimento::whereBetween('data_referencia', [$from, $to]);
        if ($account !== '') {
            $pay->where('conta', $account);
            $rec->where('conta', $account);
            $mov->where('id_conta', $account);
        }
        $pay->get()->each(fn ($r) => $rows->push(['date' => $r->data_vencimento, 'type' => 'Saída prevista', 'description' => $r->fornecedor, 'value' => -(float) $r->valor_total]));
        $rec->get()->each(fn ($r) => $rows->push(['date' => $r->data_vencimento, 'type' => 'Entrada prevista', 'description' => $r->cliente, 'value' => (float) $r->valor_total]));
        $mov->get()->each(fn ($r) => $rows->push(['date' => $r->data_referencia, 'type' => 'Movimento realizado', 'description' => $r->descricao, 'value' => $r->operacao === 'saida' ? -(float) $r->valor : (float) $r->valor]));
        $balance = 0.0;
        $rows = $rows->sortBy('date')->values()->map(function ($r) use (&$balance) {
            $balance += $r['value'];
            $r['balance'] = $balance;

            return $r;
        });
        $accounts = Conta::orderBy('nome')->get();
        if ($request->query('export') === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $f = fopen('php://output', 'w');
                fwrite($f, "\xEF\xBB\xBF");
                fputcsv($f, ['Data', 'Tipo', 'Descrição', 'Valor', 'Saldo'], ';');
                foreach ($rows as $r) {
                    fputcsv($f, [$r['date'], $r['type'], $r['description'], $r['value'], $r['balance']], ';');
                }fclose($f);
            }, 'fluxo-caixa.csv');
        }

        return view('cash-flow.index', compact('rows', 'from', 'to', 'account', 'accounts', 'balance'));
    }
}
