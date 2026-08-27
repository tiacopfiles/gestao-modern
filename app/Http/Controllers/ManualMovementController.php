<?php

namespace App\Http\Controllers;

use App\Application\Financial\ManualMovementService;
use App\Domain\Financial\Enums\ManualMovementDirection;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\ManualMovement;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Entradas e saídas lançadas à mão: PIX, tarifa, rendimento, ajuste.
 *
 * Fica dentro de Conciliação porque é ali que esses lançamentos aparecem — a
 * pessoa que precisa registrar um PIX está olhando o movimento do período.
 */
class ManualMovementController extends Controller
{
    public function __construct(private readonly ManualMovementService $movimentos) {}

    public function index(Request $request): View
    {
        $query = ManualMovement::query()->with(['conta', 'autor']);

        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->input('account_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('movement_date', '>=', (string) $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('movement_date', '<=', (string) $request->input('to'));
        }
        if ($request->filled('direction') && in_array($request->input('direction'), ['IN', 'OUT'], true)) {
            $query->where('direction', $request->input('direction'));
        }
        if ($request->filled('q')) {
            $query->where('history', 'like', '%'.$request->input('q').'%');
        }

        $records = $query->orderByDesc('movement_date')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('manual-movements.index', [
            'records' => $records,
            'contas' => Conta::query()->orderBy('nome')->get(),
            'totais' => $this->totais(clone $query),
        ]);
    }

    public function create(Request $request): View
    {
        return view('manual-movements.form', $this->dadosDoFormulario(new ManualMovement([
            'account_id' => (int) $request->input('account_id', 0),
            'movement_date' => now()->toDateString(),
            'direction' => ManualMovementDirection::In->value,
        ])));
    }

    public function edit(ManualMovement $movimento): View
    {
        return view('manual-movements.form', $this->dadosDoFormulario($movimento));
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request);

        try {
            $movimento = $this->movimentos->create($dados, $request->user()?->id);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('manual-movements.index', ['account_id' => $movimento->account_id])
            ->with('success', sprintf(
                '%s de R$ %s registrada em %s. Nenhum título foi alterado.',
                $movimento->direction->label(),
                number_format((float) $movimento->amount, 2, ',', '.'),
                $movimento->movement_date->format('d/m/Y'),
            ));
    }

    public function update(Request $request, ManualMovement $movimento): RedirectResponse
    {
        $dados = $this->validar($request);

        try {
            $this->movimentos->update($movimento, $dados, $request->user()?->id);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('manual-movements.index', ['account_id' => $movimento->account_id])
            ->with('success', 'Movimento corrigido. A alteração ficou registrada na auditoria.');
    }

    public function destroy(Request $request, ManualMovement $movimento): RedirectResponse
    {
        try {
            $this->movimentos->delete($movimento, $request->user()?->id);
        } catch (DomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('manual-movements.index')
            ->with('success', 'Movimento excluído. A exclusão ficou registrada na auditoria.');
    }

    /**
     * @return array{account_id: int, bank_account_id: int|null, document_number: string|null, movement_date: string, direction: string, amount: string, history: string, category_id: int|null, notes: string|null}
     */
    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            // Vazio é resposta válida: significa a conta bancária padrão da
            // empresa. `exists` garante que, quando preenchido, o banco existe
            // de verdade — um id solto viraria um movimento atribuído a nada.
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'movement_date' => ['required', 'date_format:Y-m-d'],
            'direction' => ['required', 'in:IN,OUT'],
            'amount' => ['required', 'string', 'max:20'],
            'history' => ['required', 'string', 'max:250'],
            'category_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'account_id' => 'conta',
            'bank_account_id' => 'conta bancária',
            'document_number' => 'nº do documento',
            'movement_date' => 'data',
            'direction' => 'tipo',
            'amount' => 'valor',
            'history' => 'histórico',
            'category_id' => 'categoria',
            'notes' => 'observação',
        ]);

        $dados['amount'] = $this->valorEmDecimal((string) $dados['amount']);
        $dados['bank_account_id'] = $dados['bank_account_id'] ?? null;
        $dados['document_number'] = $this->textoOuNulo($dados['document_number'] ?? null);
        $dados['category_id'] = $dados['category_id'] ?? null;
        $dados['notes'] = $dados['notes'] ?? null;

        return $dados;
    }

    /** Campo de texto vazio vira `null`, para não gravar string em branco. */
    private function textoOuNulo(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Aceita o valor como a pessoa digita: "2.500,00", "2500,00" ou "2500.00".
     *
     * O campo é exibido no formato brasileiro, então é nesse formato que ele
     * volta no POST — e `Money::toCents` só entende ponto decimal.
     */
    private function valorEmDecimal(string $bruto): string
    {
        $texto = trim($bruto);
        $texto = str_contains($texto, ',')
            ? str_replace(',', '.', str_replace('.', '', $texto))
            : $texto;

        return preg_replace('/[^0-9.\-]/', '', $texto) ?: '0';
    }

    /** @return array<string, mixed> */
    private function dadosDoFormulario(ManualMovement $record): array
    {
        return [
            'record' => $record,
            'contas' => Conta::query()->orderBy('nome')->get(),
            // Só contas ativas: uma conta encerrada não deve receber movimento
            // novo, mas continua existindo nos relatórios antigos.
            'bancos' => BankAccount::query()
                ->where('active', true)
                ->orderBy('company_id')
                ->orderByDesc('is_default')
                ->orderBy('bank_name')
                ->get(),
            'categorias' => Schema::hasTable('categorias')
                ? DB::table('categorias')->orderBy('nome')->get(['id', 'nome'])
                : collect(),
            'direcoes' => [ManualMovementDirection::In, ManualMovementDirection::Out],
        ];
    }

    /**
     * Entradas e saídas do filtro inteiro, somadas no banco.
     *
     * @return array{in: string, out: string}
     */
    private function totais($query): array
    {
        $linhas = $query->getQuery()->reorder()
            ->select('direction')
            ->selectRaw('SUM('.DB::connection()->getQueryGrammar()->wrap('amount').') AS total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return [
            'in' => (string) ($linhas[ManualMovementDirection::In->value] ?? '0'),
            'out' => (string) ($linhas[ManualMovementDirection::Out->value] ?? '0'),
        ];
    }
}
