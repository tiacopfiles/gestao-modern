@extends('layouts.app')
@section('title', 'Fechamento #'.$record->sequence_number.' — Sessão #'.$session->id)
@section('section', 'Conciliação v2')
@section('page-title', 'Fechamento #'.$record->sequence_number)

@section('content')
<a class="back-link" href="{{ route('reconciliation-v2.closure.history',$session) }}">← Histórico de fechamentos</a>
<header class="record-hero"><div><span class="eyebrow">Fechamento reproduzível</span><h2>{{ $account?->nome ?: 'Conta '.$session->account_id }}</h2><p>{{ $record->period_start->format('d/m/Y') }} a {{ $record->period_end->format('d/m/Y') }} · schema {{ $record->schema_version }} · motor {{ $record->engine_version }}</p><p>Fechado por {{ $record->closedByUser?->nome ?: $record->closedByUser?->username ?: '#'.$record->closed_by }} em {{ $record->closed_at->format('d/m/Y H:i:s') }}</p><p><small title="{{ $record->closure_hash }}">hash ({{ $record->hash_algorithm }}) {{ $record->closure_hash }}</small></p>@if($record->previousClosure)<p><small>Sucede o fechamento #{{ $record->previousClosure->sequence_number }} (reaberto em {{ $record->previousClosure->reopened_at?->format('d/m/Y H:i') }})</small></p>@endif</div><div class="record-value"><span class="badge {{ $record->status->value==='CLOSED'?'success':'warning' }}">{{ $record->status->value }}</span></div></header>

<section class="panel table-panel section-gap"><header class="panel-header"><div><h3>Métricas</h3></div></header><div class="table-wrap"><table><tbody>
@foreach($record->metrics as $metric)<tr><th>{{ $metric->metric_key }}</th><td>{{ $metric->metric_value }}</td></tr>@endforeach
</tbody></table></div></section>

<div class="reconciliation-v2-grid section-gap">
<section class="panel table-panel"><header class="panel-header"><div><h3>Matches incluídos</h3></div></header><div class="table-wrap"><table><thead><tr><th>Match</th><th>Status no fechamento</th><th class="align-right">Valor</th></tr></thead><tbody>
@forelse($record->matches as $row)<tr><td><a class="row-action" href="{{ route('reconciliation-v2.matches.show',[$session,$row->reconciliation_match_id]) }}">#{{ $row->reconciliation_match_id }}</a></td><td><span class="badge {{ $row->captured_status==='CONFIRMED'?'success':'neutral' }}">{{ $row->captured_status }}</span></td><td class="align-right">R$ {{ number_format((float)$row->captured_total_amount,2,',','.') }}</td></tr>
@empty<tr><td colspan="3"><div class="empty-state small"><strong>Nenhum match incluído</strong></div></td></tr>@endforelse
</tbody></table></div></section>

<section class="panel table-panel"><header class="panel-header"><div><h3>Divergências incluídas</h3></div></header><div class="table-wrap"><table><thead><tr><th>Divergência</th><th>Status no fechamento</th><th>Tipo</th></tr></thead><tbody>
@forelse($record->exceptions as $row)<tr><td><a class="row-action" href="{{ route('reconciliation-v2.exceptions.show',[$session,$row->reconciliation_exception_id]) }}">#{{ $row->reconciliation_exception_id }}</a></td><td><span class="badge {{ $row->captured_status==='JUSTIFIED'?'info':'neutral' }}">{{ $row->captured_status }}</span></td><td>{{ $row->captured_type }}</td></tr>
@empty<tr><td colspan="3"><div class="empty-state small"><strong>Nenhuma divergência incluída</strong></div></td></tr>@endforelse
</tbody></table></div></section>
</div>

@if($record->reopenings->isNotEmpty())
<section class="panel section-gap"><header class="panel-header"><div><h3>Reaberturas</h3></div></header>
@foreach($record->reopenings as $reopening)
<div class="note"><strong>{{ $reopening->reopened_at->format('d/m/Y H:i:s') }} por {{ $reopening->reopenedByUser?->nome ?: $reopening->reopenedByUser?->username ?: '#'.$reopening->reopened_by }}</strong><p>{{ $reopening->reason }}</p></div>
@endforeach
</section>
@endif

@if($record->status->value==='CLOSED')
@can('reconciliation:reopen')
<form class="panel form-panel section-gap" method="post" action="{{ route('reconciliation-v2.closure.reopen',[$session,$record]) }}">
@csrf
<header class="panel-header"><div><h3>Reabrir fechamento</h3><p>Esta é uma operação excepcional e será auditada.</p></div></header>
<div class="form-grid"><label class="field span-all"><span>Motivo obrigatório *</span><textarea name="reason" rows="4" maxlength="1000" required>{{ old('reason') }}</textarea></label></div>
<footer class="form-actions"><button class="button danger" type="submit">Reabrir fechamento</button></footer>
</form>
@endcan
@endif
@endsection
