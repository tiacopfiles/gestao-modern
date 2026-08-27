<?php

namespace App\Http\Controllers;

use App\Application\Integration\OriginSyncService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Money;
use App\Domain\Reconciliation\Closure\Enums\ReconciliationClosureStatus;
use App\Domain\Reconciliation\Enums\ReconciliationExceptionStatus;
use App\Domain\Reconciliation\Enums\ReconciliationMatchStatus;
use App\Domain\Reconciliation\Enums\ReconciliationSessionStatus;
use App\Models\BankTransaction;
use App\Models\FinancialTitle;
use App\Models\Lancamento;
use App\Models\Movimento;
use App\Models\Recebimento;
use App\Models\ReconciliationClosure;
use App\Models\ReconciliationException;
use App\Models\ReconciliationMatch;
use App\Models\ReconciliationSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $today = CarbonImmutable::today();
        $nextWeek = $today->addDays(7);

        // Periodo do painel. O filtro vale para o que e movimento (realizado no
        // periodo); "em aberto" e sempre a posicao de agora, porque saldo devedor
        // nao pertence a um mes.
        [$periodo, $de, $ate] = $this->periodo($request, $today);

        // Bloco legado. Vem das tabelas `lancamentos`/`recebimentos`/`movimentos`
        // do banco configurado — um universo DIFERENTE dos títulos modernos, que
        // chegam de Contas a Pagar / Contas a Receber pela API. Misturar os dois
        // num único número daria um total sem significado, então eles ficam em
        // painéis separados e rotulados.
        //
        // Em bases que não possuem o legado (por exemplo, um ambiente dedicado a
        // testar a integração), a seção some em vez de derrubar a tela.
        // Além de não existir, a base legada pode estar deliberadamente
        // desligada (`gestao.legacy_ui`): numa instalação alimentada por
        // sincronização ela fica vazia para sempre e só confunde quem lê o
        // painel procurando os títulos reais.
        $hasLegacy = config('gestao.legacy_ui', true)
            && Schema::hasTable('lancamentos')
            && Schema::hasTable('recebimentos');

        $totalPayable = 0.0;
        $totalReceivable = 0.0;
        $overduePayable = 0;
        $overdueReceivable = 0;
        $upcomingPayables = collect();
        $upcomingReceivables = collect();
        $recentMovements = collect();

        if ($hasLegacy) {
            $payable = Lancamento::query();
            $receivable = Recebimento::query();

            $totalPayable = (float) (clone $payable)->where('situacao', '!=', '4')->sum('valor_total');
            $totalReceivable = (float) (clone $receivable)->where('situacao', '!=', '4')->sum('valor_total');
            $overduePayable = (clone $payable)->where('situacao', '!=', '4')->where('data_vencimento', '<', $today->toDateString())->count();
            $overdueReceivable = (clone $receivable)->where('situacao', '!=', '4')->where('data_vencimento', '<', $today->toDateString())->count();

            $upcomingPayables = (clone $payable)
                ->where('situacao', '!=', '4')
                ->whereBetween('data_vencimento', [$today->toDateString(), $nextWeek->toDateString()])
                ->orderBy('data_vencimento')->limit(5)->get();
            $upcomingReceivables = (clone $receivable)
                ->where('situacao', '!=', '4')
                ->whereBetween('data_vencimento', [$today->toDateString(), $nextWeek->toDateString()])
                ->orderBy('data_vencimento')->limit(5)->get();

            if (Schema::hasTable('movimentos')) {
                $recentMovements = Movimento::query()->latest('data_referencia')->limit(6)->get();
            }
        }

        // Painel do núcleo moderno. Só é montado quando a conciliação v2 está
        // ligada e o usuário pode vê-la — mesmo critério do menu, para o
        // dashboard não anunciar um módulo que a pessoa não consegue abrir.
        $modern = null;
        if (config('reconciliation.v2_enabled', false) && Gate::allows('reconciliation:view')) {
            $modern = [
                'open_sessions' => ReconciliationSession::query()->whereIn('status', [
                    ReconciliationSessionStatus::Open->value,
                    ReconciliationSessionStatus::InReview->value,
                    ReconciliationSessionStatus::Reopened->value,
                ])->count(),
                'closed_sessions' => ReconciliationSession::query()
                    ->where('status', ReconciliationSessionStatus::Closed->value)->count(),
                'open_exceptions' => ReconciliationException::query()->whereIn('status', [
                    ReconciliationExceptionStatus::Open->value,
                    ReconciliationExceptionStatus::InReview->value,
                ])->count(),
                'justified_exceptions' => ReconciliationException::query()->whereIn('status', [
                    ReconciliationExceptionStatus::Justified->value,
                    ReconciliationExceptionStatus::Resolved->value,
                ])->count(),
                'confirmed_matches' => ReconciliationMatch::query()
                    ->where('status', ReconciliationMatchStatus::Confirmed->value)->count(),
                'closures' => ReconciliationClosure::query()
                    ->where('status', ReconciliationClosureStatus::Closed->value)->count(),
                'reopened_closures' => ReconciliationClosure::query()
                    ->where('status', ReconciliationClosureStatus::Reopened->value)->count(),
                'bank_transactions' => BankTransaction::query()->count(),
                'bank_transactions_month' => BankTransaction::query()
                    ->whereBetween('transaction_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])
                    ->count(),
                'titles_by_status' => FinancialTitle::query()
                    ->selectRaw('status, COUNT(*) AS aggregate')
                    ->groupBy('status')
                    ->pluck('aggregate', 'status'),
                'recent_sessions' => ReconciliationSession::query()->latest('id')->limit(5)->get(),
            ] + $this->modernAmounts($today, $de, $ate);
        }

        // Estado da última sincronização com cada origem legada. Falha nunca é
        // escondida: o indicador mostra ERRO com o mesmo destaque que mostra OK.
        $syncCycles = Schema::hasTable('sync_cycles')
            ? app(OriginSyncService::class)->latestCycles()
            : [];

        return view('dashboard', compact(
            'totalPayable', 'totalReceivable', 'overduePayable', 'overdueReceivable',
            'upcomingPayables', 'upcomingReceivables', 'recentMovements', 'today', 'nextWeek',
            'modern', 'hasLegacy', 'syncCycles', 'periodo', 'de', 'ate'
        ));
    }

    /**
     * Recorte de período do painel.
     *
     * @return array{0: string, 1: string, 2: string} rótulo, início, fim
     */
    private function periodo(Request $request, CarbonImmutable $hoje): array
    {
        $escolha = (string) $request->input('periodo', 'mes');

        return match ($escolha) {
            'hoje' => ['hoje', $hoje->toDateString(), $hoje->toDateString()],
            'anterior' => [
                'anterior',
                $hoje->subMonth()->startOfMonth()->toDateString(),
                $hoje->subMonth()->endOfMonth()->toDateString(),
            ],
            'personalizado' => [
                'personalizado',
                (string) ($request->input('de') ?: $hoje->startOfMonth()->toDateString()),
                (string) ($request->input('ate') ?: $hoje->endOfMonth()->toDateString()),
            ],
            default => ['mes', $hoje->startOfMonth()->toDateString(), $hoje->endOfMonth()->toDateString()],
        };
    }

    /**
     * Valores do núcleo moderno, em centavos inteiros.
     *
     * Responde, para o mês corrente: quanto entrou, quanto saiu, quanto ainda
     * está a pagar/a receber e quanto já foi realizado. Tudo somado em `int`;
     * a formatação acontece só na view.
     *
     * Somado em SQL, e não percorrendo os títulos em PHP. A versão anterior
     * carregava a tabela inteira e chamava `remainingCents()` em cada linha —
     * e esse método refaz a consulta das liquidações, então o eager load não
     * ajudava em nada. Com os títulos reais das origens isso virou 13.120
     * linhas, 13.122 consultas, 120 MB e **13 segundos** para abrir o
     * dashboard. Em agregado a mesma resposta sai em menos de um segundo.
     *
     * @return array<string, int>
     */
    private function modernAmounts(CarbonImmutable $today, string $de, string $ate): array
    {
        $monthStart = $de;
        $monthEnd = $ate;

        $bank = BankTransaction::query()
            ->whereBetween('transaction_date', [$monthStart, $monthEnd])
            ->selectRaw('direction, SUM(amount) AS total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        $credits = Money::toCents((string) ($bank['CREDIT'] ?? '0'));
        $debits = Money::toCents((string) ($bank['DEBIT'] ?? '0'));

        // "Em aberto" e a posicao de AGORA e nao respeita periodo: uma divida
        // pendente nao pertence a um mes. "Realizado" e movimento, entao segue o
        // periodo escolhido.
        $totals = $this->titleTotalsByType();
        $realizado = $this->realizadoNoPeriodo($de, $ate);

        $entradas = $realizado[FinancialTitleType::Receivable->value] ?? 0;
        $saidas = $realizado[FinancialTitleType::Payable->value] ?? 0;

        return [
            'credits_cents' => $entradas,
            'debits_cents' => $saidas,
            'net_cents' => $entradas - $saidas,
            'open_payable_cents' => $totals[FinancialTitleType::Payable->value]['open'],
            'open_receivable_cents' => $totals[FinancialTitleType::Receivable->value]['open'],
            'settled_payable_cents' => $saidas,
            'settled_receivable_cents' => $entradas,
            'open_payable_count' => $this->contarEmAberto(FinancialTitleType::Payable),
            'open_receivable_count' => $this->contarEmAberto(FinancialTitleType::Receivable),
        ];
    }

    /**
     * Quanto foi efetivamente pago/recebido no período, por tipo.
     *
     * O recorte segue a data da LIQUIDAÇÃO, não a do vencimento: é o dinheiro
     * que de fato entrou e saiu no período. REVERSAL desconta, como em todo o
     * resto do sistema.
     *
     * @return array<string, int>
     */
    private function realizadoNoPeriodo(string $de, string $ate): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $tipo = $grammar->wrap('financial_titles.type');
        $valor = $grammar->wrap('title_settlements.amount');
        $tipoLiq = $grammar->wrap('title_settlements.type');

        $linhas = DB::table('title_settlements')
            ->join('financial_titles', 'financial_titles.id', '=', 'title_settlements.financial_title_id')
            ->where('title_settlements.status', 'CONFIRMED')
            ->whereNull('financial_titles.deleted_at')
            ->whereBetween('title_settlements.settlement_date', [$de, $ate])
            ->groupBy('financial_titles.type')
            ->selectRaw("{$tipo} AS tipo")
            ->selectRaw("SUM(CASE WHEN {$tipoLiq} = 'REVERSAL' THEN -{$valor} ELSE {$valor} END) AS total")
            ->get();

        $porTipo = [];
        foreach ($linhas as $linha) {
            $porTipo[(string) $linha->tipo] = Money::toCents((string) $linha->total);
        }

        return $porTipo;
    }

    /** Quantos títulos ainda têm saldo em aberto, agora. */
    private function contarEmAberto(FinancialTitleType $tipo): int
    {
        $grammar = DB::connection()->getQueryGrammar();
        $settled = $grammar->wrap('liquidacoes.liquidado');
        $total = $grammar->wrap('financial_titles.total_amount');

        $liquidacoes = DB::table('title_settlements')
            ->select('financial_title_id')
            ->selectRaw('SUM(CASE WHEN '.$grammar->wrap('type')." = 'REVERSAL' THEN -".$grammar->wrap('amount').' ELSE '.$grammar->wrap('amount').' END) AS liquidado')
            ->where('status', 'CONFIRMED')
            ->groupBy('financial_title_id');

        return FinancialTitle::query()
            ->leftJoinSub($liquidacoes, 'liquidacoes', 'liquidacoes.financial_title_id', '=', 'financial_titles.id')
            ->where('financial_titles.status', '!=', TitleStatus::Cancelled->value)
            ->where('financial_titles.type', $tipo->value)
            ->whereRaw("{$total} - COALESCE({$settled}, 0) > 0")
            ->count();
    }

    /**
     * Em aberto e realizado por tipo, calculado no banco.
     *
     * Reproduz fielmente `FinancialTitle::remainingCents()`:
     *   - só entram liquidações com status CONFIRMED;
     *   - REVERSAL subtrai em vez de somar;
     *   - o saldo de cada título tem piso em zero, então um título liquidado a
     *     maior não pode abater o saldo de outro. É por isso que o piso fica
     *     dentro do SUM, e não sobre o total.
     *
     * O piso é escrito com CASE WHEN, e não com GREATEST: a função existe no
     * MariaDB mas não no SQLite, e a suíte roda em SQLite — usar GREATEST daria
     * um erro que só apareceria depois, e só num dos dois bancos.
     *
     * As colunas dos fragmentos crus passam pelo grammar de propósito: o alias
     * da subconsulta recebe o prefixo da conexão (`avt_`), e um nome escrito à
     * mão aqui deixaria de existir em produção.
     *
     * @return array<string, array{open: int, settled: int}>
     */
    private function titleTotalsByType(): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $settled = $grammar->wrap('liquidacoes.liquidado');
        $total = $grammar->wrap('financial_titles.total_amount');

        $liquidacoes = DB::table('title_settlements')
            ->select('financial_title_id')
            ->selectRaw('SUM(CASE WHEN '.$grammar->wrap('type')." = 'REVERSAL' THEN -".$grammar->wrap('amount').' ELSE '.$grammar->wrap('amount').' END) AS liquidado')
            ->where('status', 'CONFIRMED')
            ->groupBy('financial_title_id');

        $linhas = FinancialTitle::query()
            ->leftJoinSub($liquidacoes, 'liquidacoes', 'liquidacoes.financial_title_id', '=', 'financial_titles.id')
            ->where('financial_titles.status', '!=', TitleStatus::Cancelled->value)
            ->groupBy('financial_titles.type')
            ->selectRaw($grammar->wrap('financial_titles.type').' AS tipo')
            ->selectRaw("SUM({$total}) AS total")
            ->selectRaw("SUM(CASE WHEN {$total} - COALESCE({$settled}, 0) > 0 THEN {$total} - COALESCE({$settled}, 0) ELSE 0 END) AS aberto")
            ->get();

        $porTipo = [];

        foreach ([FinancialTitleType::Payable, FinancialTitleType::Receivable] as $tipo) {
            $porTipo[$tipo->value] = ['open' => 0, 'settled' => 0];
        }

        foreach ($linhas as $linha) {
            $total = Money::toCents((string) $linha->total);
            $aberto = Money::toCents((string) $linha->aberto);

            $porTipo[(string) $linha->tipo] = [
                'open' => $aberto,
                // Realizado é o que sobrou do total, igual ao cálculo anterior:
                // um título liquidado a maior conta como realizado até o total.
                'settled' => $total - $aberto,
            ];
        }

        return $porTipo;
    }
}
