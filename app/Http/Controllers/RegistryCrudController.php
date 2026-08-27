<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\CentroCusto;
use App\Models\Cliente;
use App\Models\Conta;
use App\Models\Documento;
use App\Models\FinancialTitle;
use App\Models\Fornecedor;
use App\Models\Situacao;
use App\Models\Tipo;
use App\Services\Audit;
use App\Support\SchemaCompat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class RegistryCrudController extends Controller
{
    private const DEFINITIONS = [
        'clientes' => [Cliente::class, 'Clientes', ['nome_fantasia', 'razao_social', 'cnpj']],
        'fornecedores' => [Fornecedor::class, 'Fornecedores', ['nome_fantasia', 'razao_social', 'cnpj']],
        'contas' => [Conta::class, 'Contas bancárias', ['nome', 'banco', 'nome_detalhado', 'dados_completos']],
        'tipos' => [Tipo::class, 'Tipos', ['nome']], 'situacoes' => [Situacao::class, 'Situações', ['nome']],
        'categorias' => [Categoria::class, 'Categorias', ['nome']], 'centros-custo' => [CentroCusto::class, 'Centros de custo', ['nome']],
    ];

    public function index(Request $request, string $kind)
    {
        [$class,$title,$searchable] = $this->definition($kind);
        $query = $class::query();
        $searchable = array_values(array_filter(
            $searchable,
            fn (string $coluna): bool => ! Schema::hasTable($searchableTable = (new $class)->getTable()) || SchemaCompat::hasColumn($searchableTable, $coluna),
        ));
        $search = trim((string) $request->query('q'));
        if ($search !== '') {
            $query->where(function ($q) use ($search, $searchable) {
                foreach ($searchable as $i => $column) {
                    $q->{$i ? 'orWhere' : 'where'}($column, 'like', "%{$search}%");
                }
            });
        }
        $records = $query->orderByDesc('id')->paginate(25)->withQueryString();

        return view('registries.crud-index', compact('records', 'search', 'title', 'kind', 'searchable'));
    }

    public function create(string $kind)
    {
        $this->assertEditavel($kind);

        [$class,$title] = $this->definition($kind);

        return view('registries.form', ['record' => new $class, 'title' => $title, 'kind' => $kind, 'fields' => $this->fields($kind), 'documents' => collect()]);
    }

    public function edit(string $kind, int $id)
    {
        $this->assertEditavel($kind);

        [$class,$title] = $this->definition($kind);
        $record = $class::findOrFail($id);
        $entity = ['clientes' => 'client', 'fornecedores' => 'supplier'][$kind] ?? null;
        $documents = $entity ? Documento::where('entidade', $entity)->where('registro_id', $id)->latest()->get() : collect();

        return view('registries.form', compact('record', 'title', 'kind', 'documents') + ['fields' => $this->fields($kind)]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        $this->assertEditavel($kind);
        [$class] = $this->definition($kind);
        $record = new $class;

        return $this->save($request, $kind, $record);
    }

    public function update(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->assertEditavel($kind);
        [$class] = $this->definition($kind);

        return $this->save($request, $kind, $class::findOrFail($id));
    }

    public function destroy(string $kind, int $id): RedirectResponse
    {
        $this->assertEditavel($kind);
        [$class] = $this->definition($kind);
        $r = $class::findOrFail($id);

        if ($emUso = $this->titulosNaConta($kind, $id)) {
            return redirect()
                ->route('registries.index', $kind)
                ->withErrors(sprintf(
                    'Esta conta não pode ser excluída: %s título(s) estão vinculados a ela. '
                    .'Excluir deixaria esses títulos e os movimentos do período sem conta identificada.',
                    number_format($emUso, 0, ',', '.'),
                ));
        }

        $r->delete();
        Audit::record($r->getTable(), $r->id, 'exclusao');

        return redirect()->route('registries.index', $kind)->with('success', 'Cadastro excluído.');
    }

    /**
     * Quantos títulos apontam para esta conta.
     *
     * `contas` é o único cadastro editável aqui, e é justamente o que os 13 mil
     * títulos referenciam por id. Excluir a conta usada por uma empresa apagaria
     * o nome que aparece na lista de títulos e na conciliação, e a próxima
     * sincronização recriaria a conta com id novo — os títulos antigos ficariam
     * apontando para um id que não existe mais. O botão era um clique e um
     * `confirm()` de distância.
     */
    private function titulosNaConta(string $kind, int $id): int
    {
        if ($kind !== 'contas' || ! Schema::hasTable('financial_titles')) {
            return 0;
        }

        return FinancialTitle::query()->where('account_id', $id)->count();
    }

    private function save(Request $request, string $kind, $record): RedirectResponse
    {
        $opcionais = ['complemento', 'bairro', 'email', 'telefone', 'celular', 'responsavel', 'cpf',
            // Complementos da conta: o nome já identifica, e exigir o banco
            // impediria de salvar as contas que vieram da sincronização sem ele.
            'banco', 'nome_detalhado', 'dados_completos'];

        $rules = [];
        foreach ($this->fields($kind) as $name => $label) {
            $rules[$name] = [in_array($name, $opcionais, true) ? 'nullable' : 'required', 'string', 'max:255'];
        }
        $data = $request->validate($rules);
        foreach ($rules as $name => $_) {
            $data[$name] = $data[$name] ?? '';
        }$action = $record->exists ? 'alteracao' : 'inclusao';
        $record->fill($data)->save();
        Audit::record($record->getTable(), $record->id, $action);

        return redirect()->route('registries.index', $kind)->with('success', 'Cadastro salvo com sucesso.');
    }

    private function definition(string $kind): array
    {
        abort_unless(isset(self::DEFINITIONS[$kind]), 404);

        return self::DEFINITIONS[$kind];
    }

    /**
     * Cadastros mantidos pelos sistemas de origem.
     *
     * A trava fica no servidor, e nao so no botao escondido: alterar por aqui
     * seria desfeito na proxima sincronizacao, e uma edicao que some sozinha e
     * pior do que uma edicao proibida.
     */
    private const SOMENTE_CONSULTA = ['clientes', 'fornecedores', 'categorias', 'tipos', 'situacoes', 'centros-custo'];

    private function assertEditavel(string $kind): void
    {
        abort_if(
            in_array($kind, self::SOMENTE_CONSULTA, true),
            403,
            'Este cadastro e mantido pelo sistema de origem e sincronizado automaticamente.',
        );
    }

    /**
     * Só oferece o campo se a coluna existir de verdade.
     *
     * `contas` é herdada do sistema antigo e varia entre instalações: numa base
     * criada do zero pela sincronização ela não tem `dados_completos`, e o
     * formulário tentaria gravar uma coluna inexistente ao salvar.
     *
     * @param  array<string, string>  $campos
     * @return array<string, string>
     */
    private function onlyExistingColumns(string $table, array $campos): array
    {
        if (! Schema::hasTable($table)) {
            return $campos;
        }

        return array_filter(
            $campos,
            fn (string $coluna): bool => SchemaCompat::hasColumn($table, $coluna),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function fields(string $kind): array
    {
        if ($kind === 'contas') {
            return $this->onlyExistingColumns('contas', [
                'nome' => 'Nome',
                'banco' => 'Banco',
                'nome_detalhado' => 'Nome detalhado',
                'dados_completos' => 'Dados completos',
            ]);
        }

        return match ($kind) {
            'clientes' => ['nome_fantasia' => 'Nome fantasia', 'razao_social' => 'Razão social', 'responsavel' => 'Responsável', 'cpf' => 'CPF', 'cnpj' => 'CNPJ', 'cep' => 'CEP', 'estado' => 'Estado', 'cidade' => 'Cidade', 'endereco' => 'Endereço', 'numero' => 'Número', 'complemento' => 'Complemento', 'bairro' => 'Bairro', 'email' => 'E-mail', 'telefone' => 'Telefone', 'celular' => 'Celular'],'fornecedores' => ['nome_fantasia' => 'Nome fantasia', 'razao_social' => 'Razão social', 'cnpj' => 'CNPJ', 'cep' => 'CEP', 'estado' => 'Estado', 'cidade' => 'Cidade', 'endereco' => 'Endereço', 'numero' => 'Número', 'complemento' => 'Complemento', 'bairro' => 'Bairro', 'email' => 'E-mail', 'telefone' => 'Telefone', 'celular' => 'Celular'],default => ['nome' => 'Nome']
        };
    }
}
