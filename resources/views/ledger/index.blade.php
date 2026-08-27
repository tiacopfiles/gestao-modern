@extends('layouts.app')
@section('title', 'Extrato')
@section('section', 'Extrato')
@section('page-title', 'Extrato da conta')

@php
    use App\Domain\Financial\Money;
    $brl = fn (int $cents) => number_format(((float) Money::fromCents(abs($cents))), 2, ',', '.');
    $sign = fn (int $cents) => $cents < 0 ? '−' : '';
@endphp

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">Quanto tinha · quanto entrou · quanto saiu · quanto ficou</span><h2>Extrato {{ $account?->nome ? '— '.$account->nome : '' }}</h2><p>Movimento bancário do período com saldo após cada lançamento.</p></div>
    @if($accountId > 0)<a class="button ghost" href="{{ route('ledger.export', request()->query()) }}">Exportar CSV</a>@endif
</div>

<section class="panel">
    <form class="filter-bar" method="get">
        <label>Conta<select name="account_id">@foreach($accounts as $item)<option value="{{ $item->id }}" @selected((string)$accountId===(string)$item->id)>{{ $item->nome }}</option>@endforeach</select></label>
        <label>De<input type="date" name="from" value="{{ $from }}"></label>
        <label>Até<input type="date" name="to" value="{{ $to }}"></label>
        <label>Saldo inicial<input name="opening_balance" value="{{ request('opening_balance') }}" inputmode="decimal" placeholder="0,00"></label>
        <label>Movimento<select name="direction"><option value="">Todos</option><option value="CREDIT" @selected(request('direction')==='CREDIT')>Entradas</option><option value="DEBIT" @selected(request('direction')==='DEBIT')>Saídas</option></select></label>
        <label>Conciliação<select name="reconciled"><option value="">Todos</option><option value="yes" @selected(request('reconciled')==='yes')>Conciliados</option><option value="no" @selected(request('reconciled')==='no')>Não conciliados</option></select></label>
        <label>Busca<input name="q" value="{{ request('q') }}" placeholder="Histórico, documento ou título"></label>
        <button class="button primary" type="submit">Aplicar</button><a class="button ghost" href="{{ route('ledger.index') }}">Limpar</a>
    </form>
</section>

<section class="stat-grid section-gap">
    <article class="stat-card"><span>Saldo inicial</span><strong>R$ {{ $sign($data['opening_cents']) }}{{ $brl($data['opening_cents']) }}</strong><small>Informado no filtro</small></article>
    <article class="stat-card positive"><span>Entradas</span><strong>R$ {{ $brl($data['credits_cents']) }}</strong><small>Créditos no período</small></article>
    <article class="stat-card negative"><span>Saídas</span><strong>R$ {{ $brl($data['debits_cents']) }}</strong><small>Débitos no período</small></article>
    <article class="stat-card {{ $data['closing_cents'] >= 0 ? 'positive' : 'negative' }}"><span>Saldo final</span><strong>R$ {{ $sign($data['closing_cents']) }}{{ $brl($data['closing_cents']) }}</strong><small>Inicial + entradas − saídas</small></article>
    <article class="stat-card {{ $data['unreconciled_cents'] > 0 ? 'warning' : '' }}"><span>Não conciliado</span><strong>R$ {{ $brl($data['unreconciled_cents']) }}</strong><small>{{ $data['count'] }} movimento(s) no período</small></article>
</section>

@if(request('opening_balance'))
    <div class="alert warning-note"><strong>Saldo inicial informado:</strong> este valor foi digitado no filtro. O Gestão ainda não possui saldo bancário contábil oficial — essa definição depende do financeiro.</div>
@endif

<section class="panel table-panel section-gap">
    <div class="table-wrap"><table><thead><tr>
        <th>Data</th><th>Documento</th><th>Histórico</th><th>Origem</th>
        <th class="align-right">Entrada</th><th class="align-right">Saída</th><th class="align-right">Saldo</th><th>Conciliação</th>
    </tr></thead><tbody>
        <tr class="ledger-opening">
            <td colspan="6"><strong>Saldo inicial</strong></td>
            <td class="align-right"><strong>R$ {{ $sign($data['opening_cents']) }}{{ $brl($data['opening_cents']) }}</strong></td>
            <td></td>
        </tr>
    @forelse($data['lines'] as $line)
        <tr>
            <td>{{ $line['date']->format('d/m/Y') }}</td>
            <td><small>{{ $line['document'] ?: '—' }}</small></td>
            <td><strong>{{ $line['description'] }}</strong>@if($line['titles'])<small>Título: {{ implode(', ', $line['titles']) }}</small>@endif</td>
            <td><span class="badge neutral">{{ $line['origin'] }}</span></td>
            <td class="align-right">@if($line['credit_cents'] > 0)<b class="money in">+ R$ {{ $brl($line['credit_cents']) }}</b>@endif</td>
            <td class="align-right">@if($line['debit_cents'] > 0)<b class="money out">− R$ {{ $brl($line['debit_cents']) }}</b>@endif</td>
            <td class="align-right"><strong>R$ {{ $sign($line['balance_cents']) }}{{ $brl($line['balance_cents']) }}</strong></td>
            <td>
                @if($line['status'] === 'CONCILIADO')<span class="badge success">CONCILIADO</span>
                @elseif($line['status'] === 'PARCIAL')<span class="badge info">PARCIAL</span>
                @else<span class="badge warning">PENDENTE</span>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="8"><div class="empty-state"><strong>Nenhum movimento no período</strong><p>Importe um extrato bancário ou ajuste o filtro de datas.</p>@can('reconciliation:manage')<a class="button primary" href="{{ route('banking.create') }}">Importar extrato OFX</a>@endcan</div></td></tr>
    @endforelse
        <tr class="ledger-closing">
            <td colspan="4"><strong>Saldo final</strong></td>
            <td class="align-right"><b class="money in">R$ {{ $brl($data['credits_cents']) }}</b></td>
            <td class="align-right"><b class="money out">R$ {{ $brl($data['debits_cents']) }}</b></td>
            <td class="align-right"><strong>R$ {{ $sign($data['closing_cents']) }}{{ $brl($data['closing_cents']) }}</strong></td>
            <td></td>
        </tr>
    </tbody></table></div>
</section>
@endsection
