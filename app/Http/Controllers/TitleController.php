<?php

namespace App\Http\Controllers;

use App\Application\Financial\SettlementService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Money;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\FinancialTitle;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Títulos do núcleo moderno (`financial_titles`) — a base do fluxo
 * "cadastra na origem, acompanha no Gestão".
 *
 * Estes títulos chegam pela API v1 (vindos do Contas a Pagar / Contas a Receber)
 * ou por seed. Esta tela existe para responder duas perguntas operacionais que
 * antes não tinham resposta na interface: **o que está em aberto** e **o que já
 * foi realizado**.
 *
 * A ação de liquidar aqui registra a REALIZAÇÃO (o título foi pago/recebido).
 * Ela não concilia e, deliberadamente, **não escreve nada no sistema de
 * origem** — integração de volta é uma decisão ainda não tomada.
 */
class TitleController extends Controller
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function index(Request $request): View
    {
        $query = FinancialTitle::query()->with(['installments', 'sourceSystem']);

        $type = $request->input('type');
        if (in_array($type, ['PAYABLE', 'RECEIVABLE'], true)) {
            $query->where('type', $type);
        }
        // A tela abre sem filtro de situação: a ordenação abaixo já entrega a
        // leitura do dia a dia (a vencer → vencido → baixado) sem esconder o
        // que já foi pago. Filtrar por uma situação específica continua
        // disponível no select.
        $status = $request->input('status');
        if (in_array($status, ['OPEN', 'PARTIALLY_SETTLED', 'SETTLED', 'CANCELLED'], true)) {
            $query->where('status', $status);
        }
        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->input('account_id'));
        }
        if ($request->filled('due_from')) {
            $query->whereDate('due_date', '>=', (string) $request->input('due_from'));
        }
        if ($request->filled('due_to')) {
            $query->whereDate('due_date', '<=', (string) $request->input('due_to'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }
        if ($request->filled('cost_center_id')) {
            $query->where('cost_center_id', (int) $request->input('cost_center_id'));
        }
        // Recorte por data de PAGAMENTO: e o que responde "o que saiu do caixa
        // neste mes", diferente do vencimento.
        if ($request->filled('paid_from') || $request->filled('paid_to')) {
            $query->whereHas('settlements', function ($s) use ($request): void {
                $s->where('status', 'CONFIRMED');
                if ($request->filled('paid_from')) {
                    $s->whereDate('settlement_date', '>=', (string) $request->input('paid_from'));
                }
                if ($request->filled('paid_to')) {
                    $s->whereDate('settlement_date', '<=', (string) $request->input('paid_to'));
                }
            });
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('party_name', 'like', $term)
                    ->orWhere('document_number', 'like', $term)
                    ->orWhere('external_id', 'like', $term);
            });
        }

        // Totais do filtro inteiro — calculados ANTES de paginar.
        //
        // `paginate()` altera o próprio builder (aplica limit/offset), então
        // clonar depois dele traz só a página corrente: com 13 mil títulos o
        // rodapé mostrava o total de 25 linhas. Some-se em SQL, não em PHP, para
        // não carregar o filtro inteiro em memória.
        $totals = $this->totals(clone $query);

        // Ordem de trabalho da tela, e não ordem de banco: primeiro o que ainda
        // vai vencer, depois o que já venceu e continua em aberto, depois o que
        // foi baixado. Cancelado fecha a lista — está fora do fluxo.
        //
        // O corte de vencido é `<= hoje`, o mesmo que o badge "Vencido" da view
        // faz com `due_date->isPast()` sobre uma data à meia-noite: um título
        // que vence hoje aparece marcado como vencido, então ele também entra
        // no grupo dos vencidos. As duas leituras precisam bater.
        //
        // `DATE()` não é enfeite: o cast `immutable_date` grava no SQLite com a
        // hora junto (`2026-08-24 00:00:00`), e ali a comparação é de texto —
        // sem normalizar, o título que vence hoje escapa do grupo. No MariaDB a
        // coluna é DATE de verdade e a função é inócua.
        $grupo = "CASE WHEN status = 'SETTLED' THEN 2 WHEN status = 'CANCELLED' THEN 3 WHEN DATE(due_date) <= ? THEN 1 ELSE 0 END";

        $records = $query->with('settlements')
            ->orderByRaw($grupo, [now()->toDateString()])
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $accounts = Conta::query()->orderBy('nome')->get();
        $accountNames = Conta::query()->pluck('nome', 'id');

        // As contas bancárias de cada empresa, para a baixa rápida da lista.
        // `settle()` exige `bank_account_id` (ADR-017) e quem está na tela é
        // quem sabe por onde o dinheiro passou — então a escolha aparece no
        // próprio formulário, em vez de o sistema arbitrar uma conta. Sem
        // conta cadastrada, a linha não oferece o botão: a baixa seria
        // recusada pela validação de qualquer forma.
        $bankAccountsByCompany = BankAccount::query()
            ->where('active', true)
            ->whereNotNull('company_id')
            ->orderByDesc('is_default')
            ->orderBy('bank_name')
            ->get()
            ->groupBy('company_id');

        // Nomes para exibir categoria e centro de custo sem expor id na tela.
        $categoryNames = Schema::hasTable('categorias') ? DB::table('categorias')->pluck('nome', 'id') : collect();
        $costCenterNames = Schema::hasTable('centrocusto') ? DB::table('centrocusto')->pluck('nome', 'id') : collect();
        $categories = Schema::hasTable('categorias') ? DB::table('categorias')->orderBy('nome')->get(['id', 'nome']) : collect();
        $costCenters = Schema::hasTable('centrocusto') ? DB::table('centrocusto')->orderBy('nome')->get(['id', 'nome']) : collect();

        return view('titles.index', compact(
            'records', 'accounts', 'accountNames', 'totals',
            'categoryNames', 'costCenterNames', 'categories', 'costCenters',
            'bankAccountsByCompany',
        ));
    }

    /**
     * Soma, em centavos, o total e o realizado do filtro inteiro.
     *
     * Dinheiro é somado como inteiro no banco (`total_amount * 100`), nunca
     * acumulado em float. Títulos cancelados ficam fora dos dois totais.
     *
     * @param  Builder<FinancialTitle>  $query
     * @return array{open: int, settled: int}
     */
    private function totals($query): array
    {
        $query->where('status', '!=', TitleStatus::Cancelled->value);

        $totalCents = (int) round((float) (clone $query)->sum('total_amount') * 100);

        // Realizado = soma das liquidações confirmadas dos títulos do filtro.
        // REVERSAL desconta, seguindo a mesma regra de FinancialTitle::settledCents().
        $ids = (clone $query)->select('financial_titles.id');
        $settledCents = (int) round((float) DB::table('title_settlements')
            ->whereIn('financial_title_id', $ids)
            ->where('status', 'CONFIRMED')
            ->selectRaw("SUM(CASE WHEN type = 'REVERSAL' THEN -amount ELSE amount END) AS aggregate")
            ->value('aggregate') * 100);

        return [
            'open' => max(0, $totalCents - $settledCents),
            'settled' => $settledCents,
        ];
    }

    public function show(FinancialTitle $title): View
    {
        $title->load(['installments', 'settlements', 'sourceSystem', 'cancellation']);
        $account = $title->account_id ? Conta::query()->find($title->account_id) : null;

        return view('titles.show', compact('title', 'account'));
    }

    /**
     * Ação rápida "marcar como pago / recebido".
     *
     * Pede o mínimo: data e, quando o título é parcelado, a parcela. O valor
     * padrão é o saldo restante, que é o caso normal.
     */
    public function settle(Request $request, FinancialTitle $title): RedirectResponse
    {
        // A conta bancária é OBRIGATÓRIA aqui, e é a diferença entre este
        // caminho e o da sincronização: quem está na tela sabe por onde o
        // dinheiro passou. Deixar em branco para o sistema "resolver depois"
        // seria recriar a convenção da conta padrão que o ADR-017 encerra.
        $data = $request->validate([
            'settlement_date' => ['required', 'date'],
            'installment_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'string'],
            'bank_account_id' => ['required', 'integer', 'min:1'],
        ], [
            'bank_account_id.required' => 'Escolha a conta bancária onde o dinheiro entrou ou saiu.',
        ]);

        $installmentId = $data['installment_id'] ?? null;
        if ($installmentId !== null && ! $title->installments->contains('id', (int) $installmentId)) {
            return back()->withErrors(['installment_id' => 'A parcela informada não pertence a este título.']);
        }

        // Sem valor informado, liquida o saldo restante por inteiro.
        $amount = $data['amount'] ?? null;
        if ($amount === null || trim($amount) === '') {
            $cents = $installmentId !== null
                ? $title->installments->firstWhere('id', (int) $installmentId)->remainingCents()
                : $title->remainingCents();
            $amount = Money::fromCents($cents);
        }

        try {
            $this->settlements->settle(
                titleId: $title->id,
                amount: $amount,
                settlementDate: (string) $data['settlement_date'],
                installmentId: $installmentId !== null ? (int) $installmentId : null,
                actorId: (int) $request->user()->getAuthIdentifier(),
                bankAccountId: (int) $data['bank_account_id'],
            );
        } catch (DomainException $exception) {
            // A validação da conta (existe? ativa? é da empresa do título?)
            // mora no serviço, então o erro dela chega aqui — e precisa aparecer
            // no campo certo para a operadora saber o que corrigir.
            $campo = str_contains($exception->getMessage(), 'onta bancária') ? 'bank_account_id' : 'amount';

            return back()->withErrors([$campo => $exception->getMessage()]);
        }

        $verb = $title->type === FinancialTitleType::Payable ? 'pago' : 'recebido';

        return back()->with(
            'success',
            "Título marcado como {$verb}. O sistema de origem não foi alterado; agora ele pode ser conciliado com o extrato.",
        );
    }
}
