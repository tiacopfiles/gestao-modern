<?php

namespace App\Http\Controllers;

use App\Application\Financial\ConciliacaoItauXlsx;
use App\Application\Financial\PeriodStatementService;
use App\Domain\Financial\CompanyGroup;
use App\Domain\Financial\Money;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\PeriodStatement;
use App\Models\PeriodStatementExclusion;
use App\Models\PeriodStatementLine;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Conciliação (Movimento do Período): criar, atualizar enquanto está aberta,
 * fechar e consultar o extrato de uma conta com saldo corrido.
 *
 * Nenhuma ação aqui altera título, liquidação ou movimento manual — é sempre
 * leitura da base mais a gravação do resumo.
 */
class PeriodStatementController extends Controller
{
    public function __construct(private readonly PeriodStatementService $statements) {}

    /**
     * Aceita o saldo como a pessoa digita: "1.000,00", "1000,00" ou "1000.00".
     *
     * O campo é exibido no formato brasileiro, então é nesse formato que ele
     * volta no POST — e `Money::toCents` só entende ponto decimal. Sem esta
     * conversão, confirmar um saldo inicial diferente de zero estoura.
     *
     * Devolve `null` para texto vazio — quem decide se isso é aceitável é
     * quem chama, não esta função. É o que permite distinguir "a pessoa
     * digitou 0" de "a pessoa não digitou nada".
     */
    private function centavosOuNulo(?string $valor): ?int
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        $negativo = str_starts_with($texto, '-');
        $texto = ltrim($texto, '-+');

        // Se tem vírgula, ela é o separador decimal e o ponto é de milhar.
        $texto = str_contains($texto, ',')
            ? str_replace(',', '.', str_replace('.', '', $texto))
            : $texto;

        $texto = preg_replace('/[^0-9.]/', '', $texto) ?? '';

        if ($texto === '') {
            return null;
        }

        return (int) ($negativo ? -1 : 1) * Money::toCents($texto);
    }

    public function index(): View
    {
        $statements = PeriodStatement::query()
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate(25);

        return view('period-statements.index', compact('statements'));
    }

    public function create(Request $request): View
    {
        // Empresas mescladas (ver `CompanyGroup`) aparecem só pelo id
        // canônico, com o nome de exibição do grupo — os outros membros
        // (hoje só a Agrocolitti "R") saem do dropdown para não duplicar.
        $contas = Conta::query()
            ->whereNotIn('id', CompanyGroup::nonCanonicalMemberIds())
            ->get()
            ->each(function (Conta $conta): void {
                $nome = CompanyGroup::displayName($conta->id);
                if ($nome !== null) {
                    $conta->nome = $nome;
                }
            })
            ->sortBy('nome')
            ->values();

        $accountId = (int) $request->input('account_id', $contas->first()->id ?? 0);
        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->input('to', now()->endOfMonth()->toDateString());

        $membrosDoGrupo = CompanyGroup::memberIds($accountId);
        $ehGrupoMesclado = count($membrosDoGrupo) > 1;

        // Contas bancárias da empresa escolhida — das duas empresas reais
        // juntas quando é um grupo mesclado, já que a conciliação combinada
        // existe justamente para mostrar as duas. Sem escolha explícita, cai
        // na padrão — que é o caso normal e evita obrigar a operadora a
        // repetir todo mês a mesma resposta.
        $bancos = $accountId > 0
            ? BankAccount::query()
                ->whereIn('company_id', $membrosDoGrupo)
                ->where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('bank_name')
                ->get()
            : collect();

        // Grupo mesclado nunca filtra por um banco só — isso esconderia o
        // movimento do outro lado, o oposto do que a mesclagem existe para
        // fazer. `bankAccountId` fica sempre nulo aqui, e a tela não deve
        // oferecer a escolha de um banco específico quando `$ehGrupoMesclado`.
        $bankAccountId = $ehGrupoMesclado
            ? null
            : ($request->filled('bank_account_id')
                ? (int) $request->input('bank_account_id')
                : $bancos->firstWhere('is_default', true)?->id);

        // Um id que não pertence à empresa escolhida é descartado em vez de
        // usado: viria de troca de conta com o banco antigo ainda na URL.
        if (! $ehGrupoMesclado && $bankAccountId !== null && ! $bancos->contains('id', $bankAccountId)) {
            $bankAccountId = $bancos->firstWhere('is_default', true)?->id;
        }

        // `null` quer dizer "ainda não informado" — de propósito, para não se
        // confundir com um saldo inicial que realmente é zero. Só existe um
        // valor sugerido quando há um período FECHADO anterior para a conta;
        // sem isso, o campo fica em branco e a pessoa precisa digitar.
        $openingCents = $request->filled('opening')
            ? $this->centavosOuNulo((string) $request->input('opening'))
            : $this->statements->suggestedOpeningCents($accountId, $from, $bankAccountId);
        $openingInformado = $openingCents !== null;

        // A prévia usa exatamente o mesmo cálculo da criação, então o que
        // aparece aqui é o que fica gravado — nada de surpresa ao confirmar.
        // Sem saldo informado ainda, mostra a prévia com base 0 só para
        // ilustrar os movimentos — o botão de confirmar fica escondido até a
        // pessoa digitar e revisar um valor de verdade.
        $preview = $accountId > 0
            ? $this->statements->preview($accountId, $from, $to, $openingCents ?? 0, $bankAccountId)
            : ['lines' => [], 'pending' => [], 'opening_cents' => 0, 'closing_cents' => 0, 'total_in_cents' => 0, 'total_out_cents' => 0];

        $conta = $contas->firstWhere('id', $accountId);
        $banco = $bancos->firstWhere('id', $bankAccountId);
        $semConta = $this->statements->contarSemConta($from, $to);
        $semBanco = $accountId > 0
            ? $this->statements->contarSemContaBancaria($accountId, $from, $to, $bankAccountId)
            : 0;

        return view('period-statements.create', compact(
            'contas', 'conta', 'accountId', 'from', 'to', 'openingCents', 'openingInformado', 'preview', 'semConta',
            'semBanco', 'bancos', 'banco', 'bankAccountId', 'ehGrupoMesclado',
        ));
    }

    /**
     * Cadastra à mão a conta bancária da empresa escolhida, sem sair da tela.
     *
     * Existe porque o cadastro só era possível pelo comando
     * `gestao:conta-bancaria`: das 42 empresas, 3 tinham conta bancária, e nas
     * outras 39 o campo "Banco" aparecia desabilitado sem nenhuma saída pela
     * interface. Quem estava abrindo a conciliação ficava travado.
     */
    public function storeBankAccount(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'account_id' => ['required', 'integer', 'min:1', 'exists:contas,id'],
            'bank_name' => ['required', 'string', 'max:120'],
            'bank_code' => ['nullable', 'string', 'max:10'],
            'agency' => ['required', 'string', 'max:20'],
            'number' => ['required', 'string', 'max:30'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'bank_name.required' => 'Informe o nome do banco.',
            'agency.required' => 'Informe a agência.',
            'number.required' => 'Informe o número da conta.',
        ]);

        $empresa = Conta::query()->findOrFail((int) $dados['account_id']);
        $voltar = ['account_id' => $empresa->id] + array_filter([
            'from' => $dados['from'] ?? null,
            'to' => $dados['to'] ?? null,
        ]);

        // `bank_accounts` tem UNIQUE em (bank_code, agency, número): a mesma
        // conta não pode ser cadastrada duas vezes. Se ela já existe, o que
        // fazer depende de quem é a dona — e "roubar" a conta de outra empresa
        // corromperia a conciliação dela, então isso é recusado em vez de
        // resolvido no chute.
        $existente = BankAccount::query()
            ->where('bank_code', (string) ($dados['bank_code'] ?? ''))
            ->where('agency', $dados['agency'])
            ->where('number', $dados['number'])
            ->first();

        if ($existente !== null && $existente->company_id !== null && $existente->company_id !== $empresa->id) {
            return redirect()
                ->route('period-statements.create', $voltar)
                ->withErrors(sprintf(
                    'A conta %s já está cadastrada para %s. Se ela mudou de empresa, corrija o cadastro antes.',
                    $existente->fullLabel(),
                    $existente->company_name ?? 'outra empresa',
                ));
        }

        // A primeira conta da empresa vira a padrão. Não é enfeite: as
        // liquidações vindas das origens chegam sem banco (`contas` e
        // `contasareceber` não têm a coluna) e só entram na conciliação da
        // conta padrão. Sem marcar, a conciliação abriria vazia — que é
        // exatamente a surpresa que essa tela existe para evitar. Já a
        // segunda conta em diante NÃO vira padrão sozinha: isso mudaria em
        // silêncio de que banco são todas as baixas históricas da empresa.
        $primeiraDaEmpresa = ! BankAccount::query()
            ->where('company_id', $empresa->id)
            ->where('active', true)
            ->exists();

        $conta = $existente ?? new BankAccount;
        $conta->fill([
            'company_id' => $empresa->id,
            'company_name' => $empresa->nome,
            'bank_name' => $dados['bank_name'],
            'bank_code' => (string) ($dados['bank_code'] ?? ''),
            'agency' => $dados['agency'],
            'number' => $dados['number'],
            'active' => true,
        ]);

        if ($primeiraDaEmpresa) {
            $conta->is_default = true;
        }

        $conta->save();

        $redirect = redirect()
            ->route('period-statements.create', $voltar + ['bank_account_id' => $conta->id])
            ->with('success', sprintf(
                'Conta %s cadastrada para %s%s.',
                $conta->fullLabel(),
                $empresa->nome,
                $conta->is_default ? ' e definida como a conta padrão da empresa' : '',
            ));

        // Cadastrar a SEGUNDA conta de uma empresa muda o comportamento do
        // sistema, e em silêncio seria a pior forma de descobrir isso: a partir
        // daqui a origem deixa de ter conta dedutível (ADR-018) e toda baixa
        // nova fica sem banco até alguém atribuir. Quem clicou precisa saber.
        if (! $primeiraDaEmpresa) {
            $redirect->with('warning', sprintf(
                '%s passa a ter mais de uma conta bancária. Como %s e %s não registram por qual banco o '
                .'pagamento saiu, as baixas novas vão ficar aguardando definição de conta em vez de entrar '
                .'na conciliação sozinhas.',
                $empresa->nome,
                'contas',
                'contasareceber',
            ));
        }

        return $redirect;
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'opening' => ['required', 'string'],
        ], [
            'opening.required' => 'Informe o saldo inicial da conta para iniciar a conciliação.',
        ]);

        $openingCents = $this->centavosOuNulo($dados['opening']);
        if ($openingCents === null) {
            return back()->withInput()->withErrors([
                'opening' => 'Informe o saldo inicial da conta para iniciar a conciliação.',
            ]);
        }

        // Grupo mesclado (ver `CompanyGroup`) nunca abre por um banco só —
        // um `bank_account_id` vindo de um formulário desatualizado seria
        // descartado aqui em vez de restringir a conciliação a metade do
        // grupo.
        $ehGrupoMesclado = count(CompanyGroup::memberIds((int) $dados['account_id'])) > 1;

        try {
            $statement = $this->statements->create(
                accountId: (int) $dados['account_id'],
                from: $dados['from'],
                to: $dados['to'],
                openingCents: $openingCents,
                actorId: $request->user()?->id,
                bankAccountId: $ehGrupoMesclado
                    ? null
                    : (isset($dados['bank_account_id']) ? (int) $dados['bank_account_id'] : null),
            );
        } catch (DomainException $e) {
            return back()->withInput()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('period-statements.show', $statement)
            ->with('success', 'Conciliação aberta. Nenhum título ou liquidação foi alterado.');
    }

    /**
     * Baixa a conciliação em XLSX, no formato das planilhas do Itaú.
     *
     * Fica atrás de `reconciliation:export` e não de `:view` porque baixar é
     * tirar o dado do sistema — quem exporta leva consigo uma cópia que ninguém
     * mais controla.
     */
    public function export(PeriodStatement $periodStatement, ConciliacaoItauXlsx $planilha): StreamedResponse
    {
        $periodStatement->load('lines');

        $conteudo = $planilha->gerar($periodStatement);
        $nome = $planilha->nomeDoArquivo($periodStatement);

        return response()->streamDownload(
            function () use ($conteudo): void {
                echo $conteudo;
            },
            $nome,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Length' => (string) strlen($conteudo),
            ],
        );
    }

    public function show(PeriodStatement $periodStatement): View
    {
        $periodStatement->load('lines', 'exclusions');
        $semConta = $this->statements->contarSemConta(
            $periodStatement->period_start->toDateString(),
            $periodStatement->period_end->toDateString(),
        );
        $semBanco = $this->statements->contarSemContaBancaria(
            (int) $periodStatement->account_id,
            $periodStatement->period_start->toDateString(),
            $periodStatement->period_end->toDateString(),
            $periodStatement->bank_account_id === null ? null : (int) $periodStatement->bank_account_id,
        );

        return view('period-statements.show', [
            'statement' => $periodStatement,
            'semConta' => $semConta,
            'semBanco' => $semBanco,
        ]);
    }

    /**
     * "Atualizar conciliação": busca de novo o que está elegível e reflete
     * sem duplicar. Só funciona em conciliação ABERTA — o serviço recusa uma
     * FECHADA, e a mensagem chega pronta para a tela.
     */
    public function refresh(Request $request, PeriodStatement $periodStatement): RedirectResponse
    {
        try {
            $resultado = $this->statements->refresh($periodStatement, $request->user()?->id);
        } catch (DomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        if (! $resultado->mudouAlgo()) {
            return back()->with('success', 'A conciliação já está atualizada.');
        }

        return back()->with('success', sprintf(
            'Conciliação atualizada com sucesso. Novos movimentos: %d.%s%s',
            $resultado->novos,
            $resultado->atualizados > 0 ? ' Corrigidos: '.$resultado->atualizados.'.' : '',
            $resultado->removidos > 0 ? ' Saíram: '.$resultado->removidos.'.' : '',
        ));
    }

    /**
     * "Não passou por esta conta": tira a linha do extrato.
     *
     * Para o pagamento que saiu por fora — PIX, outra conta do grupo. O
     * lançamento existe no Contas a Pagar, mas nunca tocou este banco, e como a
     * origem não guarda banco a conciliação o trouxe junto. Nada é apagado.
     */
    public function excludeLine(
        Request $request,
        PeriodStatement $periodStatement,
        PeriodStatementLine $line,
    ): RedirectResponse {
        $dados = $request->validate(
            ['reason' => ['nullable', 'string', 'max:250']],
            [],
            ['reason' => 'motivo'],
        );

        try {
            $this->statements->excluirLinha(
                $periodStatement,
                $line,
                $dados['reason'] ?? null,
                $request->user()?->id,
            );
        } catch (DomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        return back()->with('success', sprintf(
            'Linha removida desta conciliação: %s. O título e a baixa continuam intactos — pode devolver quando quiser.',
            $line->history,
        ));
    }

    /**
     * Reordena à mão as linhas de um dia (arrastar e soltar). Responde JSON,
     * diferente do resto deste controller (`back()`/redirect): quem chama é
     * `fetch()` do JS de drag-and-drop, não um `<form>` com reload de página.
     */
    public function reorderLines(Request $request, PeriodStatement $periodStatement): JsonResponse
    {
        $dados = $request->validate([
            'movement_date' => ['required', 'date_format:Y-m-d'],
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer'],
        ]);

        try {
            $this->statements->reordenarDia(
                $periodStatement,
                $dados['movement_date'],
                $dados['line_ids'],
                $request->user()?->id,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Ordem salva.']);
    }

    public function restoreLine(
        Request $request,
        PeriodStatement $periodStatement,
        PeriodStatementExclusion $exclusion,
    ): RedirectResponse {
        try {
            $this->statements->restaurarLinha($periodStatement, $exclusion, $request->user()?->id);
        } catch (DomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        return back()->with('success', 'Linha devolvida para a conciliação.');
    }

    /**
     * Fecha a conciliação: atualiza uma última vez e trava. Depois disso,
     * "Atualizar" não aparece mais e o relatório é o retrato definitivo do
     * período.
     */
    public function close(Request $request, PeriodStatement $periodStatement): RedirectResponse
    {
        try {
            $this->statements->close($periodStatement, $request->user()?->id);
        } catch (DomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('period-statements.show', $periodStatement)
            ->with('success', 'Conciliação fechada. Ela não muda mais.');
    }

    /**
     * Apaga só o relatório — o resumo. Títulos, liquidações e movimentos
     * manuais que ele resumia continuam intactos; criar de novo para a mesma
     * conta e período reproduz o mesmo resultado a partir deles.
     */
    public function destroy(Request $request, PeriodStatement $periodStatement): RedirectResponse
    {
        $this->statements->delete($periodStatement, $request->user()?->id);

        return redirect()
            ->route('period-statements.index')
            ->with('success', 'Relatório excluído. Nenhum título, liquidação ou movimento manual foi alterado — para conferir de novo, crie a conciliação outra vez.');
    }
}
