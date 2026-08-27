@extends('layouts.app')
@section('title', 'Histórico de fechamentos — Sessão #'.$session->id)
@section('section', 'Conciliação v2')
@section('page-title', 'Histórico de fechamentos')

@section('content')
<a class="back-link" href="{{ route('reconciliation-v2.show',$session) }}">← Sessão #{{ $session->id }}</a>
<header class="record-hero"><div><span class="eyebrow">Cadeia auditável</span><h2>{{ $account?->nome ?: 'Conta '.$session->account_id }}</h2><p>Cada fechamento é imutável; reaberturas geram uma nova linha, nunca substituem a anterior.</p></div></header>

<section class="panel table-panel section-gap"><div class="table-wrap"><table><thead><tr><th>#</th><th>Status</th><th>Fechado em</th><th>Ator</th><th>Hash</th><th></th></tr></thead><tbody>
@forelse($closures as $closure)
<tr><td>{{ $closure->sequence_number }}</td><td><span class="badge {{ $closure->status->value==='CLOSED'?'success':'warning' }}">{{ $closure->status->value }}</span></td><td>{{ $closure->closed_at->format('d/m/Y H:i') }}</td><td>{{ $closure->closedByUser?->nome ?: $closure->closedByUser?->username ?: '#'.$closure->closed_by }}</td><td><small title="{{ $closure->closure_hash }}">{{ substr($closure->closure_hash,0,12) }}…</small></td><td><a class="row-action" href="{{ route('reconciliation-v2.closure.show',[$session,$closure]) }}">→</a></td></tr>
@foreach($closure->reopenings as $reopening)
<tr><td colspan="6"><small>↳ reaberto em {{ $reopening->reopened_at->format('d/m/Y H:i') }} por {{ $reopening->reopenedByUser?->nome ?: $reopening->reopenedByUser?->username ?: '#'.$reopening->reopened_by }}: "{{ $reopening->reason }}"</small></td></tr>
@endforeach
@empty
<tr><td colspan="6"><div class="empty-state small"><strong>Nenhum fechamento ainda</strong></div></td></tr>
@endforelse
</tbody></table></div>@include('partials.pagination',['paginator'=>$closures])</section>
@endsection
