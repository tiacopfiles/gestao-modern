@extends('layouts.app')
@section('title','Divergência #'.$record->id)
@section('section','Conciliação v2')
@section('page-title','Divergência #'.$record->id)
@section('content')
<a class="back-link" href="{{ route('reconciliation-v2.show',$session) }}">← Sessão #{{ $session->id }}</a>
<header class="record-hero"><div><span class="eyebrow">Fila determinística · {{ $record->engine_version }}</span><h2>{{ $record->type->value }}</h2><p>Gerada em {{ $record->generated_at->format('d/m/Y H:i') }}</p></div><div class="record-value"><span class="badge {{ $record->status->value==='OPEN'?'danger':'neutral' }}">{{ $record->status->value }}</span><small>Sem efeito financeiro automático</small></div></header>
<section class="panel"><dl class="detail-list"><div><dt>Título</dt><dd>{{ $record->financial_title_id ?: '—' }}</dd></div><div><dt>Parcela</dt><dd>{{ $record->title_installment_id ?: '—' }}</dd></div><div><dt>Transação</dt><dd>{{ $record->bank_transaction_id ?: '—' }}</dd></div><div><dt>Valor</dt><dd>{{ $record->amount ? 'R$ '.number_format((float)$record->amount,2,',','.') : '—' }}</dd></div><div><dt>Diferença</dt><dd>{{ $record->difference_amount ? 'R$ '.number_format((float)$record->difference_amount,2,',','.') : '—' }}</dd></div><div><dt>Correlação</dt><dd>{{ $record->correlation_id }}</dd></div></dl><div class="note"><strong>Evidência</strong><p>{{ $record->evidence['explanation'] ?? '' }}</p></div></section>
@if(!in_array($record->status->value,['RESOLVED'])) @can('reconciliation:manage')<section class="panel section-gap"><header class="panel-header"><div><h3>Justificar divergência</h3><p>Registra ator, data e motivo; não cria match.</p></div></header><form class="compact-form" method="post" action="{{ route('reconciliation-v2.exceptions.justify',[$session,$record]) }}">@csrf<label class="field"><span>Justificativa obrigatória</span><input name="reason" required maxlength="1000"></label><button class="button primary">Registrar justificativa</button></form></section>@endcan @endif
@if($record->resolution_reason)<div class="alert success section-gap">{{ $record->resolution_reason }}</div>@endif
@endsection
