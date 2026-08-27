<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Conta;
use App\Models\Fornecedor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RegistryController extends Controller
{
    public function clients(Request $request): View
    {
        return $this->listing($request, Cliente::query(), 'Clientes', 'clients', ['nome_fantasia', 'razao_social', 'cnpj']);
    }

    public function suppliers(Request $request): View
    {
        return $this->listing($request, Fornecedor::query(), 'Fornecedores', 'suppliers', ['nome_fantasia', 'razao_social', 'cnpj']);
    }

    public function accounts(Request $request): View
    {
        return $this->listing($request, Conta::query(), 'Contas bancárias', 'accounts', ['nome', 'nome_detalhado', 'dados_completos']);
    }

    private function listing(Request $request, Builder $query, string $title, string $kind, array $searchable): View
    {
        $search = trim((string) $request->query('q'));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $searchable): void {
                foreach ($searchable as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $builder->{$method}($column, 'like', "%{$search}%");
                }
            });
        }
        $records = $query->orderByDesc('id')->paginate(25)->withQueryString();

        return view('registries.index', compact('records', 'search', 'title', 'kind'));
    }
}
