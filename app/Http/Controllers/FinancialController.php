<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\CentroCusto;
use App\Models\Cliente;
use App\Models\Conta;
use App\Models\Documento;
use App\Models\Fornecedor;
use App\Models\Lancamento;
use App\Models\Recebimento;
use App\Models\Situacao;
use App\Models\Tipo;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialController extends Controller
{
    public function payables(Request $request): View
    {
        return $this->listing($request, Lancamento::query(), 'payables', 'Contas a pagar');
    }

    public function receivables(Request $request): View
    {
        return $this->listing($request, Recebimento::query(), 'receivables', 'Contas a receber');
    }

    public function payable(Lancamento $lancamento): View
    {
        return $this->showRecord($lancamento, 'payable');
    }

    public function receivable(Recebimento $recebimento): View
    {
        return $this->showRecord($recebimento, 'receivable');
    }

    public function createPayable(): View
    {
        return $this->form(new Lancamento, 'payable');
    }

    public function createReceivable(): View
    {
        return $this->form(new Recebimento, 'receivable');
    }

    public function editPayable(Lancamento $lancamento): View
    {
        return $this->form($lancamento, 'payable');
    }

    public function editReceivable(Recebimento $recebimento): View
    {
        return $this->form($recebimento, 'receivable');
    }

    public function storePayable(Request $request): RedirectResponse
    {
        return $this->save($request, new Lancamento, 'payable');
    }

    public function storeReceivable(Request $request): RedirectResponse
    {
        return $this->save($request, new Recebimento, 'receivable');
    }

    public function updatePayable(Request $request, Lancamento $lancamento): RedirectResponse
    {
        return $this->save($request, $lancamento, 'payable');
    }

    public function updateReceivable(Request $request, Recebimento $recebimento): RedirectResponse
    {
        return $this->save($request, $recebimento, 'receivable');
    }

    public function destroyPayable(Lancamento $lancamento): RedirectResponse
    {
        return $this->destroyRecord($lancamento, 'payable');
    }

    public function destroyReceivable(Recebimento $recebimento): RedirectResponse
    {
        return $this->destroyRecord($recebimento, 'receivable');
    }

    public function installmentsPayable(Request $request, Lancamento $lancamento): RedirectResponse
    {
        return $this->installments($request, $lancamento, 'payable');
    }

    public function installmentsReceivable(Request $request, Recebimento $recebimento): RedirectResponse
    {
        return $this->installments($request, $recebimento, 'receivable');
    }

    private function listing(Request $request, Builder $query, string $kind, string $title): View
    {
        $search = trim((string) $request->query('q'));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $kind): void {
                $builder->where('numero_doc', 'like', "%{$search}%")
                    ->orWhere('fornecedor', 'like', "%{$search}%")
                    ->orWhere('situacao', 'like', "%{$search}%");
                if ($kind === 'receivables') {
                    $builder->orWhere('cliente', 'like', "%{$search}%");
                }
            });
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $query->whereDate('data_vencimento', $operator, $request->string($field));
            }
        }
        if ($request->filled('status')) {
            $query->where('situacao', $request->string('status'));
        }
        $summaryQuery = clone $query;
        $records = $query->orderByDesc('data_vencimento')->paginate(25)->withQueryString();
        $summary = ['count' => $records->total(), 'value' => (float) $summaryQuery->sum('valor_total')];
        $lookups = $this->lookups($kind === 'payables' ? 'payable' : 'receivable');

        return view('financial.index', compact('records', 'kind', 'title', 'summary', 'search', 'lookups'));
    }

    private function showRecord(Lancamento|Recebimento $record, string $kind): View
    {
        $entity = $kind === 'payable' ? 'payable' : 'receivable';
        $documents = Documento::query()->where('entidade', $entity)->where('registro_id', $record->id)->latest()->get();
        $lookups = $this->lookups($kind);

        return view('financial.show', compact('record', 'kind', 'documents', 'lookups'));
    }

    private function form(Lancamento|Recebimento $record, string $kind): View
    {
        $choices = [
            'accounts' => Conta::orderBy('nome')->get(), 'types' => Tipo::orderBy('nome')->get(),
            'categories' => Categoria::orderBy('nome')->get(), 'costCenters' => CentroCusto::orderBy('nome')->get(),
            'statuses' => Situacao::orderBy('nome')->get(),
            'parties' => $kind === 'payable' ? Fornecedor::orderBy('nome_fantasia')->get() : Cliente::orderBy('nome_fantasia')->get(),
        ];

        return view('financial.form', compact('record', 'kind', 'choices'));
    }

    private function save(Request $request, Lancamento|Recebimento $record, string $kind): RedirectResponse
    {
        $party = $kind === 'payable' ? 'fornecedor' : 'cliente';
        $data = $request->validate([
            $party => ['required', 'string', 'max:50'], 'numero_doc' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', 'string', 'max:255'], 'data_emissao' => ['required', 'date'], 'data_vencimento' => ['required', 'date'],
            'data_pagamento' => ['nullable', 'date'], 'categoria' => ['required', 'string', 'max:255'], 'conta' => ['required', 'string', 'max:255'],
            'centrocusto' => ['required', 'string', 'max:255'], 'situacao' => ['required', 'string', 'max:255'],
            'pc' => ['nullable', 'string', 'max:255'], 'numero_pc' => ['nullable', 'string', 'max:255'], 'competencia' => ['nullable', 'string', 'max:255'],
            'obs' => ['nullable', 'string', 'max:255'], 'valor' => ['required', 'numeric', 'min:0'], 'acrescimo' => ['nullable', 'numeric', 'min:0'], 'desconto' => ['nullable', 'numeric', 'min:0'],
        ]);
        foreach (['numero_doc', 'pc', 'numero_pc', 'competencia', 'obs'] as $field) {
            $data[$field] = $data[$field] ?? '';
        }
        $data['acrescimo'] = (float) ($data['acrescimo'] ?? 0);
        $data['desconto'] = (float) ($data['desconto'] ?? 0);
        $data['valor_total'] = round((float) $data['valor'] + $data['acrescimo'] - $data['desconto'], 2);
        $data['tipo_lancamento'] = 'manual';
        if (empty($data['data_pagamento'])) {
            unset($data['data_pagamento']);
        }
        $action = $record->exists ? 'alteracao' : 'inclusao';
        $record->fill($data)->save();
        Audit::record($record->getTable(), $record->id, $action);

        return redirect()->route($kind === 'payable' ? 'payables.show' : 'receivables.show', $record)->with('success', 'Registro salvo com sucesso.');
    }

    private function destroyRecord(Lancamento|Recebimento $record, string $kind): RedirectResponse
    {
        $record->delete();
        Audit::record($record->getTable(), $record->id, 'exclusao');

        return redirect()->route($kind === 'payable' ? 'payables.index' : 'receivables.index')->with('success', 'Registro excluído com segurança.');
    }

    private function installments(Request $request, Lancamento|Recebimento $record, string $kind): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:2', 'max:120'], 'first_due' => ['required', 'date']]);
        $quantity = (int) $data['quantity'];
        $base = $record->replicate();
        $amount = round((float) $record->valor_total / $quantity, 2);
        $record->update(['pc' => '1', 'numero_pc' => (string) $quantity, 'valor' => $amount, 'valor_total' => $amount, 'data_vencimento' => $data['first_due']]);
        $allocated = $amount;
        for ($number = 2; $number <= $quantity; $number++) {
            $installment = $base->replicate();
            $value = $number === $quantity ? round((float) $base->valor_total - $allocated, 2) : $amount;
            $allocated += $value;
            $installment->fill(['pc' => (string) $number, 'numero_pc' => (string) $quantity, 'valor' => $value, 'valor_total' => $value, 'data_vencimento' => date('Y-m-d', strtotime($data['first_due'].' +'.($number - 1).' months'))]);
            $installment->save();
            Audit::record($record->getTable(), $installment->id, 'parcela');
        }
        Audit::record($record->getTable(), $record->id, 'parcelamento');

        return redirect()->route($kind === 'payable' ? 'payables.index' : 'receivables.index')->with('success', "{$quantity} parcelas geradas com o total preservado.");
    }

    private function lookups(string $kind): array
    {
        return [
            'accounts' => Conta::pluck('nome', 'id'), 'types' => Tipo::pluck('nome', 'id'),
            'categories' => Categoria::pluck('nome', 'id'), 'costCenters' => CentroCusto::pluck('nome', 'id'),
            'statuses' => Situacao::pluck('nome', 'id'),
            'parties' => ($kind === 'payable' ? Fornecedor::query() : Cliente::query())->pluck('nome_fantasia', 'id'),
        ];
    }
}
