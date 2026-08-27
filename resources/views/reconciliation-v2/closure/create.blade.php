@extends('layouts.app')
@section('title', 'Preparar fechamento — Sessão #'.$session->id)
@section('section', 'Conciliação v2')
@section('page-title', 'Preparar fechamento')

@section('content')
<a class="back-link" href="{{ route('reconciliation-v2.show',$session) }}">← Sessão #{{ $session->id }}</a>
<header class="record-hero"><div><span class="eyebrow">Pré-fechamento</span><h2>{{ $account?->nome ?: 'Conta '.$session->account_id }}</h2><p>{{ $session->period_start->format('d/m/Y') }} a {{ $session->period_end->format('d/m/Y') }} · motor {{ $preview['payload']['engine_version'] }}</p></div></header>

@if($errors->any())
<div class="note danger section-gap"><strong>Não é possível fechar</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<section class="panel table-panel section-gap"><header class="panel-header"><div><h3>Métricas calculadas agora</h3><p>Ainda não persistidas; recalculadas a cada visita a esta tela.</p></div></header>
<div class="table-wrap"><table><tbody>
<tr><th>Transações bancárias no período</th><td>{{ $preview['metrics']['bank_transactions_count'] }}</td></tr>
<tr><th>Crédito total</th><td>R$ {{ number_format((float)$preview['metrics']['credit_total'],2,',','.') }}</td></tr>
<tr><th>Débito total</th><td>R$ {{ number_format((float)$preview['metrics']['debit_total'],2,',','.') }}</td></tr>
<tr><th>Títulos envolvidos</th><td>{{ $preview['metrics']['titles_count'] }}</td></tr>
<tr><th>Valor conciliado</th><td>R$ {{ number_format((float)$preview['metrics']['reconciled_amount'],2,',','.') }}</td></tr>
<tr><th>Valor não conciliado</th><td>R$ {{ number_format((float)$preview['metrics']['unreconciled_amount'],2,',','.') }}</td></tr>
<tr><th>Matches manuais / assistidos</th><td>{{ $preview['metrics']['matches_manual_count'] }} / {{ $preview['metrics']['matches_assisted_count'] }}</td></tr>
<tr><th>Divergências justificadas / abertas</th><td>{{ $preview['metrics']['exceptions_justified_count'] }} / {{ $preview['metrics']['exceptions_open_count'] }}</td></tr>
</tbody></table></div>
</section>

<section class="panel table-panel section-gap"><header class="panel-header"><div><h3>Matches incluídos</h3><p>{{ $matches->count() }} match(es) associado(s) à sessão.</p></div></header>
<div class="table-wrap"><table><thead><tr><th>Match</th><th>Status</th><th class="align-right">Valor</th></tr></thead><tbody>
@forelse($matches as $match)<tr><td>#{{ $match->id }}</td><td><span class="badge {{ $match->status->value==='CONFIRMED'?'success':'neutral' }}">{{ $match->status->value }}</span></td><td class="align-right">R$ {{ number_format((float)$match->transactionAllocations->sum('allocated_amount'),2,',','.') }}</td></tr>
@empty<tr><td colspan="3"><div class="empty-state small"><strong>Nenhum match nesta sessão</strong></div></td></tr>@endforelse
</tbody></table></div>
</section>

<section class="panel section-gap"><header class="panel-header"><div><h3>Checklist</h3></div></header>
<ul class="checklist">
@forelse($readiness->blockers as $blocker)<li><span class="badge danger">Bloqueador</span> {{ $blocker['message'] }}</li>@empty @endforelse
@forelse($readiness->warnings as $warning)<li><span class="badge warning">Aviso</span> {{ $warning['message'] }}</li>@empty @endforelse
@if($readiness->ready && $readiness->warnings===[])<li><span class="badge success">OK</span> Nenhum impedimento encontrado.</li>@endif
</ul>
</section>

<section class="panel form-panel section-gap">
@if($readiness->ready)
<form method="post" action="{{ route('reconciliation-v2.closure.store',$session) }}">
@csrf
<header class="panel-header"><div><h3>Confirmar fechamento</h3><p>Esta ação é reversível apenas por reabertura excepcional, registrada e com motivo obrigatório.</p></div></header>
<div class="form-grid"><label class="field span-all checkbox-field"><input type="checkbox" name="confirm" value="1" required> <span>Revisei as métricas e o checklist acima e desejo fechar esta sessão definitivamente.</span></label></div>
<footer class="form-actions"><button class="button primary" type="submit">Confirmar fechamento definitivamente</button></footer>
</form>
@else
<p>Fechamento bloqueado até que os itens marcados como "Bloqueador" acima sejam resolvidos.</p>
@endif
</section>
@endsection
