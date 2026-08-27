<?php

namespace App\Http\Controllers;

use App\Models\Conciliacao;
use App\Models\Conta;
use App\Models\Lancamento;
use App\Models\Movimento;
use App\Models\Recebimento;
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ReconciliationController extends Controller
{
    public function index(Request $request): View
    {
        $query = Conciliacao::query();
        if ($request->filled('account')) {
            $query->where('id_conta', $request->string('account'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        $records = $query->orderByDesc('data_cadastro')->paginate(20)->withQueryString();
        $accounts = Conta::orderBy('nome')->get();

        return view('reconciliations.index', compact('records', 'accounts'));
    }

    public function create(): View
    {
        $accounts = Conta::orderBy('nome')->get();
        $conciliacao = new Conciliacao;

        return view('reconciliations.form', compact('accounts', 'conciliacao'));
    }

    public function edit(Conciliacao $conciliacao): View
    {
        $accounts = Conta::orderBy('nome')->get();

        return view('reconciliations.form', compact('accounts', 'conciliacao'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['id_conta' => ['required', 'string', 'max:255'], 'data_inicial' => ['required', 'date'], 'data_final' => ['required', 'date', 'after_or_equal:data_inicial']]);
        $date = CarbonImmutable::parse($data['data_inicial']);
        $data += ['mes' => $date->format('m'), 'ano' => $date->format('Y'), 'status' => 'ABERTA', 'data_cadastro' => now()->toDateString()];
        $record = Conciliacao::create($data);
        Audit::record('conciliacoes', $record->id, 'inclusao');

        return redirect()->route('reconciliations.show', $record)->with('success', 'Conciliação criada.');
    }

    public function update(Request $request, Conciliacao $conciliacao): RedirectResponse
    {
        $data = $request->validate(['id_conta' => ['required', 'string', 'max:255'], 'data_inicial' => ['required', 'date'], 'data_final' => ['required', 'date', 'after_or_equal:data_inicial']]);
        $date = CarbonImmutable::parse($data['data_inicial']);
        $data += ['mes' => $date->format('m'), 'ano' => $date->format('Y')];
        $conciliacao->update($data);
        Audit::record('conciliacoes', $conciliacao->id, 'alteracao');

        return redirect()->route('reconciliations.show', $conciliacao)->with('success', 'Conciliação atualizada.');
    }

    public function show(Conciliacao $conciliacao): View
    {
        $ledger = $this->ledger($conciliacao);
        $balance = 0.0;
        $ledger = $ledger->map(function (array $item) use (&$balance) {
            $balance += $item['signed'];
            $item['balance'] = $balance;

            return $item;
        });
        $totals = ['entries' => $ledger->where('signed', '>', 0)->sum('signed'), 'exits' => abs($ledger->where('signed', '<', 0)->sum('signed')), 'balance' => $balance];
        $account = Conta::find($conciliacao->id_conta);

        return view('reconciliations.show', compact('conciliacao', 'ledger', 'totals', 'account'));
    }

    public function status(Conciliacao $conciliacao): RedirectResponse
    {
        $conciliacao->status = mb_strtoupper((string) $conciliacao->status) === 'ARQUIVADO' ? 'ABERTO' : 'ARQUIVADO';
        $conciliacao->save();
        Audit::record('conciliacoes', $conciliacao->id, 'status');

        return back()->with('success', 'Status atualizado.');
    }

    public function destroy(Conciliacao $conciliacao): RedirectResponse
    {
        $conciliacao->delete();
        Audit::record('conciliacoes', $conciliacao->id, 'exclusao');

        return redirect()->route('reconciliations.index')->with('success', 'Conciliação excluída.');
    }

    public function export(Conciliacao $conciliacao)
    {
        $rows = $this->ledger($conciliacao);
        $name = "conciliacao-{$conciliacao->id}.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Data', 'Origem', 'Histórico', 'Documento', 'Entrada', 'Saída'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['date'], $r['origin'], $r['description'], $r['document'], $r['signed'] > 0 ? $r['value'] : '', $r['signed'] < 0 ? $r['value'] : ''], ';');
            } fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function ledger(Conciliacao $c): Collection
    {
        $items = collect();
        Lancamento::where('conta', $c->id_conta)->whereBetween('data_pagamento', [$c->data_inicial, $c->data_final])->get()->each(fn ($r) => $items->push(['date' => $r->data_pagamento, 'origin' => 'Pagamento', 'description' => $r->fornecedor, 'document' => $r->numero_doc, 'value' => (float) $r->valor_total, 'signed' => -(float) $r->valor_total]));
        Recebimento::where('conta', $c->id_conta)->whereBetween('data_pagamento', [$c->data_inicial, $c->data_final])->get()->each(fn ($r) => $items->push(['date' => $r->data_pagamento, 'origin' => 'Recebimento', 'description' => $r->cliente ?: $r->fornecedor, 'document' => $r->numero_doc, 'value' => (float) $r->valor_total, 'signed' => (float) $r->valor_total]));
        Movimento::where('id_conta', $c->id_conta)->whereBetween('data_referencia', [$c->data_inicial, $c->data_final])->get()->each(fn ($r) => $items->push(['date' => $r->data_referencia, 'origin' => 'Movimento', 'description' => $r->descricao, 'document' => '#'.$r->id, 'value' => (float) $r->valor, 'signed' => $r->operacao === 'saida' ? -(float) $r->valor : (float) $r->valor]));

        return $items->sortBy(fn ($r) => $r['date'].($r['origin'] === 'Movimento' ? '1' : '0'))->values();
    }
}
