@extends('layouts.app')
@section('title', 'Movimentos manuais')
@section('section', 'Conciliação')
@section('page-title', 'Movimentos manuais')

@php
    $brl = fn ($valor) => 'R$ '.number_format((float) $valor, 2, ',', '.');
@endphp

@section('content')

<div class="page-heading">
    <div>
        <span class="eyebrow">Conciliação</span>
        <h2>Movimentos manuais</h2>
        <p>PIX, tarifa, rendimento, transferência e ajustes — o que se moveu na conta sem vir de Contas a pagar ou Contas a receber.</p>
    </div>
    @can('reconciliation:manage')
        <a class="button primary" href="{{ route('manual-movements.create', request()->only('account_id')) }}">+ Novo movimento</a>
    @endcan
</div>

<section class="stat-grid stat-grid-2">
    <article class="stat-card positive">
        <span>Entradas</span>
        <strong>{{ $brl($totais['in']) }}</strong>
        <small>Somadas no filtro atual</small>
    </article>
    <article class="stat-card negative">
        <span>Saídas</span>
        <strong>{{ $brl($totais['out']) }}</strong>
        <small>Somadas no filtro atual</small>
    </article>
</section>

<section class="panel table-panel section-gap">
    <form class="filter-bar" method="get">
        <label class="search"><span>⌕</span>
            <input name="q" value="{{ request('q') }}" placeholder="Buscar no histórico">
        </label>
        <label>Conta
            <select name="account_id">
                <option value="">Todas</option>
                @foreach($contas as $c)
                    <option value="{{ $c->id }}" @selected((string) request('account_id') === (string) $c->id)>{{ $c->nome }}</option>
                @endforeach
            </select>
        </label>
        <label>Tipo
            <select name="direction">
                <option value="">Todos</option>
                <option value="IN" @selected(request('direction') === 'IN')>Entrada</option>
                <option value="OUT" @selected(request('direction') === 'OUT')>Saída</option>
            </select>
        </label>
        <label>De<input type="date" name="from" value="{{ request('from') }}"></label>
        <label>Até<input type="date" name="to" value="{{ request('to') }}"></label>
        <button class="button primary" type="submit">Filtrar</button>
        <a class="button ghost" href="{{ route('manual-movements.index') }}">Limpar</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Conta</th>
                    <th>Histórico</th>
                    <th class="align-right">Entrada</th>
                    <th class="align-right">Saída</th>
                    <th>Lançado por</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($records as $m)
                <tr>
                    <td>{{ $m->movement_date->format('d/m/Y') }}</td>
                    <td><strong>{{ $m->conta?->nome ?: '—' }}</strong></td>
                    <td>
                        <strong>{{ $m->history }}</strong>
                        @if($m->notes)<small>{{ \Illuminate\Support\Str::limit($m->notes, 90) }}</small>@endif
                    </td>
                    <td class="align-right">
                        @if($m->direction->isEntrada())<b class="money in">+ {{ $brl($m->amount) }}</b>@endif
                    </td>
                    <td class="align-right">
                        @unless($m->direction->isEntrada())<b class="money out">− {{ $brl($m->amount) }}</b>@endunless
                    </td>
                    <td>
                        {{ $m->autor?->nome ?: $m->autor?->username ?: '—' }}
                        <small>{{ $m->created_at?->format('d/m/Y H:i') }}</small>
                    </td>
                    <td class="inline-actions">
                        @can('reconciliation:manage')
                            <a class="row-action" href="{{ route('manual-movements.edit', $m) }}" aria-label="Corrigir">✎</a>
                            <form method="post" action="{{ route('manual-movements.destroy', $m) }}" class="inline-form"
                                  onsubmit="return confirm('Excluir este movimento? A exclusão fica registrada na auditoria.')">
                                @csrf @method('delete')
                                <button class="row-action danger" type="submit" aria-label="Excluir">×</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <span>⇄</span>
                            <strong>Nenhum movimento manual registrado</strong>
                            <p>Use <em>Novo movimento</em> para lançar um PIX, uma tarifa, um rendimento ou um ajuste que não veio dos sistemas de origem.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.pagination', ['paginator' => $records])
</section>

@endsection
