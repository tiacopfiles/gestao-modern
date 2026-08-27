<?php

namespace App\Http\Controllers;

use App\Application\Banking\OfxImportService;
use App\Application\Banking\WebBankImportClient;
use App\Models\BankTransaction;
use App\Models\Conta;
use App\Models\ImportBatch;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Importação e consulta de extratos bancários pela interface web.
 *
 * A Fase 3 já entregava a ingestão bancária, mas somente por `/api/v1`. Sem uma
 * tela, a conciliação exibia "Importe fatos bancários antes de conciliar" sem
 * oferecer nenhum caminho para fazê-lo — o operador dependia de um chamado
 * técnico ou de um script. Este controller expõe o mesmo `OfxImportService`
 * usado pela API, com as mesmas regras de deduplicação e auditoria.
 */
class BankStatementController extends Controller
{
    public function __construct(
        private readonly OfxImportService $imports,
        private readonly WebBankImportClient $webClient,
    ) {}

    public function index(Request $request): View
    {
        $accounts = Conta::query()->orderBy('nome')->get();

        $query = BankTransaction::query()->with('importBatch');
        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->input('account_id'));
        }
        if (in_array($request->input('direction'), ['CREDIT', 'DEBIT'], true)) {
            $query->where('direction', $request->input('direction'));
        }
        if ($request->filled('from')) {
            $query->whereDate('transaction_date', '>=', (string) $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('transaction_date', '<=', (string) $request->input('to'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('description_original', 'like', $term)
                    ->orWhere('external_id', 'like', $term)
                    ->orWhere('document_number', 'like', $term);
            });
        }

        $records = $query->orderByDesc('transaction_date')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $accountNames = Conta::query()->pluck('nome', 'id');

        $batches = ImportBatch::query()->latest('id')->limit(10)->get();

        return view('banking.index', compact('records', 'accounts', 'accountNames', 'batches'));
    }

    public function create(): View
    {
        $accounts = Conta::query()->orderBy('nome')->get();
        $maxBytes = (int) config('banking.ofx_max_bytes');

        return view('banking.import', compact('accounts', 'maxBytes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $maxKilobytes = max(1, (int) floor(((int) config('banking.ofx_max_bytes')) / 1024));

        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:contas,id'],
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
        ]);

        try {
            $result = $this->imports->import(
                $request->file('file'),
                (int) $data['account_id'],
                $this->webClient->resolve(),
                (string) Str::uuid(),
            );
        } catch (DomainException $exception) {
            // Arquivo inválido, formato não suportado, tamanho excedido e
            // conflito de identidade chegam aqui como DomainException.
            return back()->withErrors(['file' => $exception->getMessage()])->withInput();
        }

        $batch = $result->batch;

        if ($result->duplicateFile) {
            return redirect()->route('banking.batches.show', $batch)
                ->with('success', 'Este extrato já havia sido importado. Nenhum fato bancário foi duplicado.');
        }

        $message = sprintf(
            'Extrato importado: %d fato(s) novo(s), %d duplicado(s) ignorado(s), %d recusado(s).',
            (int) $batch->imported_items,
            (int) $batch->duplicate_items,
            (int) $batch->rejected_items,
        );

        return redirect()->route('banking.batches.show', $batch)->with('success', $message);
    }

    public function showBatch(ImportBatch $batch): View
    {
        $batch->load('sourceSystem');
        $items = $batch->items()->paginate(50);
        $account = Conta::query()->find($batch->account_id);

        return view('banking.batch', compact('batch', 'items', 'account'));
    }
}
