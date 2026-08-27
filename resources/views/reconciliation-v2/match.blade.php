@extends('layouts.app')
@section('title', 'Match #'.$record->id)
@section('section', 'Conciliação v2')
@section('page-title', 'Match #'.$record->id)

@section('content')
<a class="back-link" href="{{ route('reconciliation-v2.show',$session) }}">← Sessão #{{ $session->id }}</a>
<header class="record-hero"><div><span class="eyebrow">Decisão {{ $record->method->value }}</span><h2>{{ $account?->nome ?: 'Conta '.$session->account_id }}</h2><p>Confirmado por {{ $record->confirmer?->nome ?: $record->confirmer?->username ?: '#'.$record->confirmed_by }} em {{ $record->confirmed_at->format('d/m/Y H:i:s') }}</p><p>Correlação atual: {{ $record->correlation_id }}</p></div><div class="record-value"><span class="badge {{ $record->status->value==='CONFIRMED'?'success':'neutral' }}">{{ $record->status->value }}</span><small>Registro persistente; nunca excluído pelo fluxo</small></div></header>

<div class="reconciliation-v2-grid">
    <section class="panel table-panel"><header class="panel-header"><div><h3>Alocações de títulos</h3></div></header><div class="table-wrap"><table><thead><tr><th>Título</th><th>Parcela</th><th>Tipo</th><th class="align-right">Alocado</th></tr></thead><tbody>@foreach($record->titleAllocations as $allocation)<tr><td><strong>{{ $allocation->financialTitle->party_name ?: 'Título #'.$allocation->financial_title_id }}</strong><small>{{ $allocation->financialTitle->document_number }}</small></td><td>#{{ $allocation->titleInstallment?->installment_number }} · {{ $allocation->titleInstallment?->due_date?->format('d/m/Y') }}</td><td>{{ $allocation->financialTitle->type->value }}</td><td class="align-right"><strong>R$ {{ number_format((float)$allocation->allocated_amount,2,',','.') }}</strong></td></tr>@endforeach</tbody></table></div></section>
    <section class="panel table-panel"><header class="panel-header"><div><h3>Alocações bancárias</h3></div></header><div class="table-wrap"><table><thead><tr><th>Transação</th><th>Data</th><th>Direção</th><th class="align-right">Alocado</th></tr></thead><tbody>@foreach($record->transactionAllocations as $allocation)<tr><td><strong>{{ $allocation->bankTransaction->description_original }}</strong><small>{{ $allocation->bankTransaction->external_id }}</small></td><td>{{ $allocation->bankTransaction->transaction_date->format('d/m/Y') }}</td><td>{{ $allocation->bankTransaction->direction->value }}</td><td class="align-right"><strong>R$ {{ number_format((float)$allocation->allocated_amount,2,',','.') }}</strong></td></tr>@endforeach</tbody></table></div></section>
</div>

@if($record->status->value==='VOIDED')
<section class="panel section-gap"><header class="panel-header"><div><h3>Desfazimento</h3><p>Executado por {{ $record->voider?->nome ?: $record->voider?->username ?: '#'.$record->voided_by }} em {{ $record->voided_at?->format('d/m/Y H:i:s') }}</p></div></header><div class="note"><strong>Motivo</strong><p>{{ $record->void_reason }}</p></div></section>
@else
@can('reconciliation:manage')<form class="panel form-panel section-gap" method="post" action="{{ route('reconciliation-v2.matches.void',[$session,$record]) }}">@csrf<header class="panel-header"><div><h3>Desfazer match</h3><p>As alocações deixarão de consumir saldo, mas todo o histórico será preservado.</p></div></header><div class="form-grid"><label class="field span-all"><span>Motivo obrigatório *</span><textarea name="reason" rows="4" maxlength="1000" required>{{ old('reason') }}</textarea></label></div><footer class="form-actions"><button class="button danger" type="submit">Desfazer sem excluir</button></footer></form>@endcan
@endif
@endsection
