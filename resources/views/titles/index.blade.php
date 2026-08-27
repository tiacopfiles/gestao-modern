@extends('layouts.app')
@php
    use App\Domain\Financial\Money;
    $brl = fn (int $cents) => 'R$ '.number_format(((float) Money::fromCents(abs($cents))), 2, ',', '.');

    $tipo = request('type');
    $titulo = match ($tipo) {
        'PAYABLE' => 'Contas a pagar',
        'RECEIVABLE' => 'Contas a receber',
        default => 'Todos os títulos',
    };
@endphp
@section('title', $titulo)
@section('section', 'Financeiro')
@section('page-title', $titulo)

@section('content')

<div class="page-heading">
    <div>
        <span class="eyebrow">Sincronizado de Contas a Pagar e Contas a Receber</span>
        <h2>{{ $titulo }}</h2>
        <p>
            @if($tipo === 'PAYABLE')
                O que ainda há para pagar e o que já foi pago.
            @elseif($tipo === 'RECEIVABLE')
                O que ainda há para receber e o que já foi recebido.
            @else
                A pagar e a receber juntos, com o tipo indicado em cada linha.
            @endif
        </p>
    </div>
</div>

<section class="stat-grid stat-grid-2">
    <article class="stat-card warning">
        <span>Em aberto</span>
        <strong>{{ $brl($totals['open']) }}</strong>
        <small>Ainda não {{ $tipo === 'RECEIVABLE' ? 'recebido' : 'realizado' }} no filtro atual</small>
    </article>
    <article class="stat-card positive">
        <span>{{ $tipo === 'PAYABLE' ? 'Pago' : ($tipo === 'RECEIVABLE' ? 'Recebido' : 'Realizado') }}</span>
        <strong>{{ $brl($totals['settled']) }}</strong>
        <small>Já baixado no filtro atual</small>
    </article>
</section>

<section class="panel table-panel section-gap">
    <form class="filter-bar" method="get">
        @if($tipo)<input type="hidden" name="type" value="{{ $tipo }}">@endif

        <label class="search"><span>⌕</span>
            <input name="q" value="{{ request('q') }}" placeholder="Buscar por {{ $tipo === 'RECEIVABLE' ? 'cliente' : 'fornecedor' }} ou documento">
        </label>

        @unless($tipo)
            <label>Tipo
                <select name="type">
                    <option value="">Todos</option>
                    <option value="PAYABLE" @selected(request('type')==='PAYABLE')>A pagar</option>
                    <option value="RECEIVABLE" @selected(request('type')==='RECEIVABLE')>A receber</option>
                </select>
            </label>
        @endunless

        @php
            // Abre em "Todas" — mesmo default do controller. A ordenação da
            // lista (a vencer → vencido → baixado) é que organiza a leitura.
            $statusFiltro = request('status', '');
        @endphp
        <label>Situação
            <select name="status">
                <option value="" @selected($statusFiltro==='')>Todas</option>
                <option value="OPEN" @selected($statusFiltro==='OPEN')>Em aberto</option>
                <option value="PARTIALLY_SETTLED" @selected($statusFiltro==='PARTIALLY_SETTLED')>Parcial</option>
                <option value="SETTLED" @selected($statusFiltro==='SETTLED')>{{ $tipo === 'RECEIVABLE' ? 'Recebido' : ($tipo === 'PAYABLE' ? 'Pago' : 'Realizado') }}</option>
            </select>
        </label>

        <label>Vence de<input type="date" name="due_from" value="{{ request('due_from') }}"></label>
        <label>Vence até<input type="date" name="due_to" value="{{ request('due_to') }}"></label>

        @if($categories->isNotEmpty())
            <label>Categoria
                <select name="category_id">
                    <option value="">Todas</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected((string)request('category_id')===(string)$c->id)>{{ $c->nome }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if($costCenters->isNotEmpty())
            <label>Centro de custo
                <select name="cost_center_id">
                    <option value="">Todos</option>
                    @foreach($costCenters as $c)
                        <option value="{{ $c->id }}" @selected((string)request('cost_center_id')===(string)$c->id)>{{ $c->nome }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label>Conta
            <select name="account_id">
                <option value="">Todas</option>
                @foreach($accounts as $item)
                    <option value="{{ $item->id }}" @selected((string)request('account_id')===(string)$item->id)>{{ $item->nome }}</option>
                @endforeach
            </select>
        </label>

        <button class="button primary" type="submit">Filtrar</button>
        <a class="button ghost" href="{{ route('titles.index', $tipo ? ['type' => $tipo] : []) }}">Limpar</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    @unless($tipo)<th>Tipo</th>@endunless
                    <th>Documento</th>
                    <th>{{ $tipo === 'RECEIVABLE' ? 'Cliente' : ($tipo === 'PAYABLE' ? 'Fornecedor' : 'Cliente / Fornecedor') }}</th>
                    <th>Emissão</th>
                    <th>Vencimento</th>
                    <th class="align-right">Valor</th>
                    <th>Situação</th>
                    <th>{{ $tipo === 'RECEIVABLE' ? 'Recebido em' : 'Pago em' }}</th>
                    <th>Categoria</th>
                    <th>Centro de custo</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($records as $title)
                @php
                    $emAberto = $title->remainingCents();
                    $ehPagar = $title->type->value === 'PAYABLE';
                    $baixa = $title->settlements->where('status', 'CONFIRMED')->sortBy('settlement_date')->last();
                    $vencido = $emAberto > 0 && $title->due_date->isPast();
                @endphp
                <tr>
                    @unless($tipo)
                        <td><span class="badge {{ $ehPagar ? 'neutral' : 'info' }}">{{ $ehPagar ? 'A pagar' : 'A receber' }}</span></td>
                    @endunless
                    <td>{{ $title->document_number ?: '—' }}</td>
                    <td><strong>{{ $title->party_name ?: '—' }}</strong></td>
                    <td>{{ $title->issue_date?->format('d/m/Y') ?: '—' }}</td>
                    <td>
                        {{ $title->due_date->format('d/m/Y') }}
                        @if($vencido)<small class="danger-text">Vencido</small>@endif
                    </td>
                    <td class="align-right"><strong>R$ {{ number_format((float) $title->total_amount, 2, ',', '.') }}</strong>
                        @if($emAberto > 0 && $emAberto !== Money::toCents((string) $title->total_amount))
                            <small>Em aberto {{ $brl($emAberto) }}</small>
                        @endif
                    </td>
                    <td>
                        @if($title->status->value === 'SETTLED')
                            <span class="badge success">{{ $ehPagar ? 'Pago' : 'Recebido' }}</span>
                        @elseif($title->status->value === 'PARTIALLY_SETTLED')
                            <span class="badge info">Parcial</span>
                        @elseif($title->status->value === 'CANCELLED')
                            <span class="badge neutral">Cancelado</span>
                        @else
                            <span class="badge {{ $vencido ? 'danger' : 'warning' }}">Em aberto</span>
                        @endif
                    </td>
                    <td>{{ $baixa?->settlement_date?->format('d/m/Y') ?: '—' }}</td>
                    <td>{{ $categoryNames[$title->category_id] ?? '—' }}</td>
                    <td>{{ $costCenterNames[$title->cost_center_id] ?? '—' }}</td>
                    <td class="inline-actions">
                        @can('payments')
                            @if($emAberto > 0 && $title->status->value !== 'CANCELLED')
                                @php($contasDoTitulo = $bankAccountsByCompany[$title->account_id] ?? collect())
                                {{--
                                    A conta bancária é escolhida aqui, não arbitrada pelo sistema:
                                    `settle()` a exige (ADR-017) porque quem está na tela sabe por
                                    onde o dinheiro passou. Sem conta cadastrada na empresa não há
                                    o que escolher, e a baixa seria recusada — então a linha diz o
                                    que fazer em vez de oferecer um botão que falha.
                                --}}
                                @if($contasDoTitulo->isEmpty())
                                    <span class="muted-text" style="font-size:12px"
                                          title="A baixa precisa da conta bancária por onde o dinheiro passou.">
                                        Sem banco cadastrado
                                    </span>
                                @else
                                    <form method="post" action="{{ route('titles.settle', $title) }}" class="inline-form">
                                        @csrf
                                        <input type="hidden" name="settlement_date" value="{{ now()->toDateString() }}">
                                        @if($title->installments->count() === 1)
                                            <input type="hidden" name="installment_id" value="{{ $title->installments->first()->id }}">
                                        @endif
                                        <select name="bank_account_id" required
                                                aria-label="Conta bancária da baixa"
                                                style="max-width:190px;font-size:12px">
                                            @foreach($contasDoTitulo as $contaBancaria)
                                                <option value="{{ $contaBancaria->id }}">{{ $contaBancaria->shortLabel() }}</option>
                                            @endforeach
                                        </select>
                                        <button class="button ghost small" type="submit"
                                                title="Registra a baixa apenas no Gestão. O sistema de origem não é alterado.">
                                            Marcar como {{ $ehPagar ? 'pago' : 'recebido' }}
                                        </button>
                                    </form>
                                @endif
                            @endif
                        @endcan
                        <a class="row-action" href="{{ route('titles.show', $title) }}" aria-label="Abrir">→</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11">
                        <div class="empty-state">
                            <span>⌕</span>
                            <strong>Nenhum título encontrado</strong>
                            <p>Ajuste os filtros ou use <em>Sincronizar agora</em> no início para buscar novidades nas origens.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.pagination',['paginator'=>$records])
</section>
@endsection
