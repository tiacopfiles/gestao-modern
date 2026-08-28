@extends('layouts.app')
@section('title', 'Conciliação')
@section('section', 'Conciliação')
@section('page-title', 'Conciliação')

@php
    use App\Domain\Financial\Money;
    $brl = fn (?int $cents) => $cents === null ? '' : 'R$ '.number_format((float) Money::fromCents(abs($cents)), 2, ',', '.');
    $brlSinal = fn (int $cents) => ($cents < 0 ? '-' : '').'R$ '.number_format((float) Money::fromCents(abs($cents)), 2, ',', '.');
    $diaAnterior = $statement->period_start->copy()->subDay();
    $aberta = $statement->isOpen();
    // Só quem pode gerenciar a conciliação arrasta linha — igual à regra de
    // exclusão logo abaixo, e conciliação fechada não reordena mais do que
    // atualiza.
    $podeReordenar = $aberta && \Illuminate\Support\Facades\Gate::allows('reconciliation:manage');

    // As duas seções vivem na mesma tabela do banco, separadas por `section`:
    // o movimento tem saldo corrido, a pendência não é movimento nenhum.
    $movimento = $statement->lines->reject->isPendente();
    $pendentes = $statement->lines->filter->isPendente()->values();

    // O último movimento de cada dia carrega o saldo com que aquele dia fechou.
    // Marcá-lo é o que torna a tela conferível contra a planilha do banco, que a
    // equipe lê dia a dia — e o saldo do dia não depende da ordem DENTRO do dia,
    // então continua certo mesmo quando a planilha lista os lançamentos daquele
    // dia numa sequência diferente da nossa.
    $fechaODia = $movimento
        ->groupBy(fn ($linha) => $linha->movement_date->toDateString())
        ->map->last()
        ->pluck('id')
        ->flip();
@endphp

@section('content')

{{-- Sucesso e erro são renderizados pelo layout (layouts/app), não aqui: repetir mostrava o mesmo aviso duas vezes na tela. --}}

{{--
    Cabeçalho único: identifica a conta, o período, o saldo e o estado — e é
    daqui que se abre, fecha ou exclui, sem precisar procurar em outro lugar.
--}}
<section class="panel" style="margin-bottom:14px">
    <div style="padding:16px 18px;display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap">
        <div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <strong style="font-size:16px">{{ $statement->account_bank ?: 'Banco não informado' }} · {{ $statement->account_name }}</strong>
                <span class="badge {{ $aberta ? 'info' : 'success' }}">{{ $statement->status->label() }}</span>
            </div>
            <div style="margin-top:4px;color:#666;font-size:13px">
                {{ $statement->period_start->format('d/m/Y') }} — {{ $statement->period_end->format('d/m/Y') }}
                · {{ $statement->line_count }} movimento(s)
            </div>
            <div style="margin-top:6px;color:#8993a4;font-size:11px">
                Criada em {{ $statement->generated_at->format('d/m/Y H:i') }}
                @if($statement->generated_by)por {{ optional($statement->generatedBy)->nome ?? optional($statement->generatedBy)->username ?? ('usuário #'.$statement->generated_by) }}@endif
                @if($aberta && $statement->last_synced_at)
                    · Última atualização: {{ $statement->last_synced_at->format('d/m/Y H:i') }}
                @endif
                @if(! $aberta && $statement->closed_at)
                    · Fechada em {{ $statement->closed_at->format('d/m/Y H:i') }}
                    @if($statement->closed_by)por {{ optional($statement->closedBy)->nome ?? optional($statement->closedBy)->username ?? ('usuário #'.$statement->closed_by) }}@endif
                @endif
            </div>
        </div>

        @canany(['reconciliation:manage', 'reconciliation:export'])
            <div class="heading-actions" style="align-items:flex-start">
                {{--
                    Baixar exige `export`, separado de `manage`: levar o dado
                    para fora do sistema é uma permissão própria, e quem só
                    confere a conciliação não precisa dela.
                --}}
                @can('reconciliation:export')
                    <a class="button ghost" href="{{ route('period-statements.export', $statement) }}"
                       title="Baixa em XLSX no mesmo formato das planilhas de conciliação do Itaú.">
                        ↓ Baixar planilha
                    </a>
                @endcan
                @can('reconciliation:manage')
                @if($aberta)
                    <a class="button ghost" href="{{ route('manual-movements.create', ['account_id' => $statement->account_id]) }}">+ Movimento</a>
                    <form method="post" action="{{ route('period-statements.refresh', $statement) }}" class="inline-form">
                        @csrf
                        <button class="button ghost" type="submit">↻ Atualizar conciliação</button>
                    </form>
                    <form method="post" action="{{ route('period-statements.close', $statement) }}" class="inline-form"
                          onsubmit="return confirm('Fechar esta conciliação?\n\nDepois de fechada ela não pode mais ser atualizada — vira o retrato definitivo do período.')">
                        @csrf
                        <button class="button primary" type="submit">Fechar</button>
                    </form>
                @endif
                <form method="post" action="{{ route('period-statements.destroy', $statement) }}" class="inline-form"
                      onsubmit="return confirm('Excluir esta conciliação?\n\nOs títulos, liquidações e movimentos manuais que ela resume NÃO são apagados — só este resumo.{{ $aberta ? '' : ' A conciliação está FECHADA — excluir apaga também o retrato definitivo do período, e uma nova conciliação teria de ser aberta do zero.' }}')">
                    @csrf @method('delete')
                    <button class="button danger" type="submit">Excluir</button>
                </form>
                @endcan
            </div>
        @endcanany
    </div>

    {{-- Saldo: quatro números, leitura rápida. O rótulo do quarto muda com o
         estado — "Saldo atual" enquanto o mês ainda está acontecendo,
         "Saldo final" só depois de fechada. --}}
    <div class="stat-grid" style="padding:0 18px 18px;margin-bottom:0">
        <article class="stat-card">
            <span>Saldo inicial</span>
            <strong>{{ $brlSinal($statement->opening_balance_cents) }}</strong>
        </article>
        <article class="stat-card positive">
            <span>Entradas</span>
            <strong>{{ $brl($statement->total_in_cents) }}</strong>
        </article>
        <article class="stat-card negative">
            <span>Saídas</span>
            <strong>{{ $brl($statement->total_out_cents) }}</strong>
        </article>
        <article class="stat-card {{ $statement->closing_balance_cents >= 0 ? 'positive' : 'negative' }}">
            <span>{{ $aberta ? 'Saldo atual' : 'Saldo final' }}</span>
            <strong>{{ $brlSinal($statement->closing_balance_cents) }}</strong>
        </article>
    </div>
</section>

@if($semConta > 0)
    <div class="alert" style="margin-bottom:14px;border-color:#ecd9aa;background:#fff9e8;color:#815d12">
        {{ $semConta }} movimento(s) realizado(s) neste período aguardando definição de conta — não pertencem a
        nenhuma conciliação até o cadastro na origem ser corrigido.
    </div>
@endif

@if($semBanco > 0)
    <div class="alert" style="margin-bottom:14px;border-color:#ecd9aa;background:#fff9e8;color:#815d12">
        {{ $semBanco }} movimento(s) realizado(s) neste período sem conta bancária definida — esta empresa tem
        mais de uma conta ativa, então não dá para deduzir por onde o dinheiro passou. Defina a conta de cada um
        para que entrem na conciliação certa.
    </div>
@endif

{{--
    O relatório tem centenas de linhas e as colunas só fazem sentido junto com o
    cabeçalho: rolando, some a referência de qual coluna é entrada e qual é saída.

    A tabela rola dentro do próprio box, e não com a página, porque `.table-panel`
    tem overflow:hidden e `.table-wrap` overflow-x:auto — com esses ancestrais um
    sticky preso à janela simplesmente não gruda. A regra virou padrão de
    `.table-wrap` em gestao.css e vale para todas as tabelas do sistema.
--}}

<section class="panel table-panel">
    <div class="table-wrap">
        {{--
            `data-reorder-url` não muda com a linha nem com o dia — é a mesma
            rota para qualquer dia deste período, só o corpo do POST muda. O
            JS de arrastar-e-soltar lê daqui em vez de montar a URL na mão.
        --}}
        <table @if($podeReordenar) data-reorder-url="{{ route('period-statements.lines.reorder', $statement) }}" @endif>
            <thead>
                <tr>
                    <th>DATA</th>
                    <th>N° DOC</th>
                    <th>ID</th>
                    <th>HISTÓRICO</th>
                    <th class="align-right">ENTRADA</th>
                    <th class="align-right">SAÍDA</th>
                    <th class="align-right">SALDO</th>
                </tr>
            </thead>
            <tbody>
                {{--
                    Primeira linha: o saldo com que o período começa. Só o
                    histórico e o saldo são preenchidos — as demais células ficam
                    vazias de propósito, porque não é um movimento.
                --}}
                <tr class="opening-row" style="background:#f6f7f9;font-weight:600">
                    <td></td>
                    <td></td>
                    <td></td>
                    <td>Saldo referente ao dia {{ $diaAnterior->format('d/m/Y') }}</td>
                    <td></td>
                    <td></td>
                    <td class="align-right">{{ $brlSinal($statement->opening_balance_cents) }}</td>
                </tr>

                @forelse($movimento as $linha)
                    <tr
                        @if($podeReordenar)
                            draggable="true"
                            class="reorder-row"
                            data-line-id="{{ $linha->id }}"
                            data-day="{{ $linha->movement_date->toDateString() }}"
                        @endif
                    >
                        @if($podeReordenar)
                            <td class="row-drag-handle" title="Arrastar para reordenar dentro do mesmo dia {{ $linha->movement_date->format('d/m/Y') }} — a ordem escolhida fica salva">⠿⠿</td>
                        @endif
                        <td>{{ $linha->movement_date->format('d/m/Y') }}</td>
                        <td>{{ $linha->document_number }}</td>
                        <td>{{ $linha->origin_id }}</td>
                        <td>{{ $linha->history }}</td>
                        <td class="align-right">{{ $brl($linha->amount_in_cents) }}</td>
                        <td class="align-right">{{ $brl($linha->amount_out_cents) }}</td>
                        <td class="align-right{{ $fechaODia->has($linha->id) ? ' saldo-do-dia' : '' }}"
                            @if($fechaODia->has($linha->id)) title="Saldo com que o dia {{ $linha->movement_date->format('d/m/Y') }} fechou" @endif>
                            {{ $brlSinal($linha->running_balance_cents) }}
                        </td>
                        @if($aberta)
                            @can('reconciliation:manage')
                                <td class="align-right">
                                    {{--
                                        Para o pagamento que saiu por fora (PIX, outra
                                        conta do grupo): existe no Contas a Pagar, mas
                                        nunca passou por este banco.
                                    --}}
                                    <form method="post"
                                          action="{{ route('period-statements.lines.exclude', [$statement, $linha]) }}"
                                          class="inline-form">
                                        @csrf
                                        <button class="row-action" type="submit"
                                                title="Não passou por esta conta — tirar do extrato (dá para devolver depois)">×</button>
                                    </form>
                                </td>
                            @endcan
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <span>⌕</span>
                                <strong>Nenhum movimento neste período</strong>
                                <p>Não houve pagamento, recebimento nem lançamento manual nesta conta entre
                                   {{ $statement->period_start->format('d/m/Y') }} e
                                   {{ $statement->period_end->format('d/m/Y') }}.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse

            </tbody>
            {{-- Totais em tfoot para ficarem colados no rodapé enquanto se rola. --}}
            <tfoot>
                <tr style="font-weight:600">
                    <td colspan="4">Totais do período</td>
                    <td class="align-right">{{ $brl($statement->total_in_cents) }}</td>
                    <td class="align-right">{{ $brl($statement->total_out_cents) }}</td>
                    <td class="align-right">{{ $brlSinal($statement->closing_balance_cents) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>

@include('period-statements.partials.pending', ['pendentes' => $pendentes, 'brl' => $brl])

<div style="margin-top:14px">
    <a class="button ghost" href="{{ route('period-statements.index') }}">← Todas as conciliações</a>
    @unless($aberta)
        <a class="button ghost" href="{{ route('period-statements.create', ['account_id' => $statement->account_id]) }}">Nova conciliação</a>
    @endunless
</div>

@if($podeReordenar)
    <script src="{{ asset('assets/period-statement-reorder.js') }}" defer></script>
@endif

@endsection
