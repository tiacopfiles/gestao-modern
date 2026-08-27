@extends('layouts.app')
@section('title', 'Dashboard')
@section('section', 'Visão geral')
@section('page-title', 'Visão geral')

@php
    use App\Domain\Financial\Money;
    $brl = fn (?int $cents) => 'R$ '.number_format(((float) Money::fromCents(abs((int) $cents))), 2, ',', '.');
    $sinal = fn (int $cents) => ($cents < 0 ? '- ' : '').'R$ '.number_format(((float) Money::fromCents(abs($cents))), 2, ',', '.');

    $rotuloPeriodo = match ($periodo) {
        'hoje' => 'hoje',
        'anterior' => 'mês anterior',
        'personalizado' => \Illuminate\Support\Carbon::parse($de)->format('d/m/Y').' a '.\Illuminate\Support\Carbon::parse($ate)->format('d/m/Y'),
        default => 'mês atual',
    };
@endphp

@section('content')

<div class="page-heading">
    <div>
        <span class="eyebrow">Contas a Pagar e Contas a Receber, sincronizados</span>
        <h2>Olá, {{ explode(' ', trim(auth()->user()->nome ?: auth()->user()->username))[0] }}</h2>
        <p>Movimento de <strong>{{ $rotuloPeriodo }}</strong> e a posição em aberto de hoje.</p>
    </div>
</div>

{{-- Filtro de período: vale para os cards de movimento, não para o em aberto. --}}
<form class="period-bar" method="get" action="{{ route('dashboard') }}">
    <div class="period-chips">
        @foreach(['hoje' => 'Hoje', 'mes' => 'Mês atual', 'anterior' => 'Mês anterior', 'personalizado' => 'Escolher datas'] as $valor => $rotulo)
            <button type="submit" name="periodo" value="{{ $valor }}"
                    class="chip {{ $periodo === $valor ? 'chip-active' : '' }}">{{ $rotulo }}</button>
        @endforeach
    </div>
    @if($periodo === 'personalizado')
        <div class="period-dates">
            <label>De<input type="date" name="de" value="{{ $de }}"></label>
            <label>Até<input type="date" name="ate" value="{{ $ate }}"></label>
            <input type="hidden" name="periodo" value="personalizado">
            <button class="button primary" type="submit">Aplicar</button>
        </div>
    @endif
</form>

@if($modern)
<section class="stat-grid">
    <article class="stat-card">
        <span>A pagar em aberto</span>
        <strong>{{ $brl($modern['open_payable_cents']) }}</strong>
        <small>{{ number_format($modern['open_payable_count'] ?? 0, 0, ',', '.') }} título(s) · posição de hoje</small>
        <a class="card-link" href="{{ route('titles.index', ['type' => 'PAYABLE', 'status' => 'OPEN']) }}">Ver contas a pagar →</a>
    </article>

    <article class="stat-card">
        <span>A receber em aberto</span>
        <strong>{{ $brl($modern['open_receivable_cents']) }}</strong>
        <small>{{ number_format($modern['open_receivable_count'] ?? 0, 0, ',', '.') }} título(s) · posição de hoje</small>
        <a class="card-link" href="{{ route('titles.index', ['type' => 'RECEIVABLE', 'status' => 'OPEN']) }}">Ver contas a receber →</a>
    </article>

    <article class="stat-card negative">
        <span>Pago no período</span>
        <strong>{{ $brl($modern['settled_payable_cents']) }}</strong>
        <small>Saídas de {{ $rotuloPeriodo }}</small>
    </article>

    <article class="stat-card positive">
        <span>Recebido no período</span>
        <strong>{{ $brl($modern['settled_receivable_cents']) }}</strong>
        <small>Entradas de {{ $rotuloPeriodo }}</small>
    </article>
</section>

<section class="result-strip {{ $modern['net_cents'] >= 0 ? 'result-positive' : 'result-negative' }}">
    <div>
        <span>Resultado do período</span>
        <strong>{{ $sinal($modern['net_cents']) }}</strong>
    </div>
    <div class="result-parts">
        <span>Entradas <b>{{ $brl($modern['settled_receivable_cents']) }}</b></span>
        <span>Saídas <b>{{ $brl($modern['settled_payable_cents']) }}</b></span>
    </div>
    <a class="button ghost" href="{{ route('period-statements.create', ['from' => $de, 'to' => $ate]) }}">Abrir conciliação →</a>
</section>
@endif

@include('partials.sync-panel', ['syncCycles' => $syncCycles])

@if($hasLegacy)
<details class="legacy-details">
    <summary>Base legada deste banco</summary>
    <section class="stat-grid">
        <article class="stat-card"><span>A receber (legado)</span><strong>R$ {{ number_format($totalReceivable, 2, ',', '.') }}</strong></article>
        <article class="stat-card"><span>A pagar (legado)</span><strong>R$ {{ number_format($totalPayable, 2, ',', '.') }}</strong></article>
        <article class="stat-card"><span>Vencidos</span><strong>{{ $overduePayable + $overdueReceivable }}</strong></article>
    </section>
</details>
@endif

@endsection
