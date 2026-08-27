<?php

namespace App\Http\Controllers;

use App\Models\Conta;
use App\Models\Documento;
use App\Models\Movimento;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MovementController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $query = Movimento::query();
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('descricao', 'like', "%{$search}%")->orWhere('operacao', 'like', "%{$search}%")->orWhere('id_conta', 'like', "%{$search}%"));
        }
        if ($request->filled('from')) {
            $query->whereDate('data_referencia', '>=', $request->string('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('data_referencia', '<=', $request->string('to'));
        }
        $records = $query->orderByDesc('data_referencia')->paginate(25)->withQueryString();

        return view('movements.index', compact('records', 'search'));
    }

    public function create(): View
    {
        return $this->form(new Movimento);
    }

    public function edit(Movimento $movimento): View
    {
        return $this->form($movimento);
    }

    public function show(Movimento $movimento): View
    {
        $documents = Documento::where('entidade', 'movement')->where('registro_id', $movimento->id)->latest()->get();

        return view('movements.show', compact('movimento', 'documents'));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Movimento);
    }

    public function update(Request $request, Movimento $movimento): RedirectResponse
    {
        return $this->save($request, $movimento);
    }

    public function destroy(Movimento $movimento): RedirectResponse
    {
        $movimento->delete();
        Audit::record('movimentos', $movimento->id, 'exclusao');

        return redirect()->route('movements.index')->with('success', 'Movimento excluído.');
    }

    private function form(Movimento $movimento): View
    {
        $accounts = Conta::orderBy('nome')->get();

        return view('movements.form', compact('movimento', 'accounts'));
    }

    private function save(Request $request, Movimento $movimento): RedirectResponse
    {
        $data = $request->validate(['id_conta' => ['required', 'string', 'max:255'], 'data_referencia' => ['required', 'date'], 'descricao' => ['required', 'string', 'max:255'], 'operacao' => ['required', 'in:entrada,saida,saldo'], 'valor' => ['required', 'numeric', 'min:0']]);
        $action = $movimento->exists ? 'alteracao' : 'inclusao';
        $movimento->fill($data)->save();
        Audit::record('movimentos', $movimento->id, $action);

        return redirect()->route('movements.show', $movimento)->with('success', 'Movimento salvo com sucesso.');
    }
}
