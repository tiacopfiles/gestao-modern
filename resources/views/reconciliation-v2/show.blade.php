@extends('layouts.app')
@section('title', 'Sessão de conciliação #'.$session->id)
@section('section', 'Conciliação v2')
@section('page-title', 'Sessão #'.$session->id)

@section('content')
<a class="back-link" href="{{ route('reconciliation-v2.index') }}">← Sessões v2</a>
<header class="record-hero"><div><span class="eyebrow">Match manual persistente</span><h2>{{ $account?->nome ?: 'Conta '.$session->account_id }}</h2><p>{{ $session->period_start->format('d/m/Y') }} a {{ $session->period_end->format('d/m/Y') }} · correlação {{ $session->correlation_id }}</p></div><div class="record-value"><span class="badge {{ ['OPEN'=>'warning','IN_REVIEW'=>'info','CLOSED'=>'success','REOPENED'=>'warning'][$session->status->value] }}">{{ ['OPEN'=>'ABERTA','IN_REVIEW'=>'EM REVISÃO','CLOSED'=>'FECHADA','REOPENED'=>'REABERTA'][$session->status->value] }}</span><small>Não é fechamento financeiro</small></div></header>

@if(config('reconciliation.matching_enabled', false))
<section class="reconciliation-summary"><div><span class="summary-icon">≋</span><div><strong>Matching determinístico {{ config('reconciliation_matching.engine_version') }}</strong><small>Sugestões explicáveis; sempre dependem de aceite humano e revalidação.</small></div></div>@can('reconciliation:manage')<form method="post" action="{{ route('reconciliation-v2.matching.generate',$session) }}">@csrf<button class="button primary" type="submit">Gerar sugestões e divergências</button></form>@endcan</section>

<section class="panel table-panel section-gap"><header class="panel-header"><div><h3>Sugestões</h3><p>Score prioriza a fila; não confirma nem liquida.</p></div></header><form class="filter-bar" method="get"><label>Status<select name="candidate_status"><option value="">Todos</option>@foreach(['PENDING','ACCEPTED','REJECTED','STALE'] as $value)<option @selected(request('candidate_status')===$value)>{{ $value }}</option>@endforeach</select></label><label>Confiança<select name="confidence"><option value="">Todas</option>@foreach(['HIGH','MEDIUM','LOW'] as $value)<option @selected(request('confidence')===$value)>{{ $value }}</option>@endforeach</select></label><button class="button ghost">Filtrar filas</button></form><div class="table-wrap"><table><thead><tr><th>Candidato</th><th>Tipo</th><th>Score</th><th>Status</th><th>Composição</th><th></th></tr></thead><tbody>@forelse($candidates as $candidate)<tr><td>#{{ $candidate->id }}<small>{{ $candidate->engine_version }}</small></td><td>{{ $candidate->type->value }}</td><td><strong>{{ $candidate->score }}/100</strong><span class="badge info">{{ $candidate->confidence }}</span></td><td><span class="badge {{ $candidate->status->value==='PENDING'?'warning':'neutral' }}">{{ $candidate->status->value }}</span></td><td>{{ $candidate->titleAllocations->count() }} título(s) ↔ {{ $candidate->transactionAllocations->count() }} transação(ões)</td><td><a class="row-action" href="{{ route('reconciliation-v2.candidates.show',[$session,$candidate]) }}">→</a></td></tr>@empty<tr><td colspan="6"><div class="empty-state small"><strong>Nenhuma sugestão gerada</strong></div></td></tr>@endforelse</tbody></table></div>@include('partials.pagination',['paginator'=>$candidates])</section>

<section class="panel table-panel section-gap"><header class="panel-header"><div><h3>Fila de divergências</h3><p>Exceções preservadas para investigação e justificativa.</p></div></header><form class="filter-bar" method="get"><label>Status<select name="exception_status"><option value="">Todos</option>@foreach(['OPEN','IN_REVIEW','RESOLVED','JUSTIFIED'] as $value)<option @selected(request('exception_status')===$value)>{{ $value }}</option>@endforeach</select></label><label>Tipo<select name="exception_type"><option value="">Todos</option>@foreach(['NO_CANDIDATE','AMBIGUOUS_CANDIDATES','AMOUNT_MISMATCH','STRONG_IDENTIFIER_CONFLICT','PARTIALLY_RECONCILED_REMAINDER','MISSING_REQUIRED_DATA'] as $value)<option @selected(request('exception_type')===$value)>{{ $value }}</option>@endforeach</select></label><label>ID título/parcela/transação<input name="resource_id" value="{{ request('resource_id') }}"></label><button class="button ghost">Filtrar divergências</button></form><div class="table-wrap"><table><thead><tr><th>Divergência</th><th>Tipo</th><th>Status</th><th>Referências</th><th>Valor</th><th></th></tr></thead><tbody>@forelse($exceptions as $exception)<tr><td>#{{ $exception->id }}</td><td>{{ $exception->type->value }}</td><td><span class="badge {{ $exception->status->value==='OPEN'?'danger':'neutral' }}">{{ $exception->status->value }}</span></td><td>Parcela {{ $exception->title_installment_id ?: '—' }} · transação {{ $exception->bank_transaction_id ?: '—' }}</td><td>{{ $exception->amount ? 'R$ '.number_format((float)$exception->amount,2,',','.') : '—' }}</td><td><a class="row-action" href="{{ route('reconciliation-v2.exceptions.show',[$session,$exception]) }}">→</a></td></tr>@empty<tr><td colspan="6"><div class="empty-state small"><strong>Nenhuma divergência gerada</strong></div></td></tr>@endforelse</tbody></table></div>@include('partials.pagination',['paginator'=>$exceptions])</section>
@endif

<section class="panel table-panel section-gap">
    <header class="panel-header"><div><h3>Match manual</h3><p>Fluxo manual preservado e independente das sugestões.</p></div></header>
    <form class="filter-bar reconciliation-filter" method="get">
        <label>Tipo<select name="title_type"><option value="">Todos</option><option value="PAYABLE" @selected(request('title_type')==='PAYABLE')>A pagar</option><option value="RECEIVABLE" @selected(request('title_type')==='RECEIVABLE')>A receber</option></select></label>
        <label>Documento<input name="document" value="{{ request('document') }}"></label>
        <label>Parte<input name="party" value="{{ request('party') }}"></label>
        <label>Vencimento de<input type="date" name="due_from" value="{{ request('due_from') }}"></label>
        <label>até<input type="date" name="due_to" value="{{ request('due_to') }}"></label>
        <label>Valor título<input name="title_amount" inputmode="decimal" placeholder="1000.00" value="{{ request('title_amount') }}"></label>
        <label>Status título<select name="title_status"><option value="">Todos</option>@foreach(['OPEN'=>'Aberto','PARTIALLY_SETTLED'=>'Parcialmente liquidado','SETTLED'=>'Liquidado'] as $value=>$label)<option value="{{ $value }}" @selected(request('title_status')===$value)>{{ $label }}</option>@endforeach</select></label>
        <label>Banco de<input type="date" name="bank_from" value="{{ request('bank_from') }}"></label>
        <label>até<input type="date" name="bank_to" value="{{ request('bank_to') }}"></label>
        <label>Descrição banco<input name="bank_description" value="{{ request('bank_description') }}"></label>
        <label>Valor banco<input name="bank_amount" inputmode="decimal" placeholder="1000.00" value="{{ request('bank_amount') }}"></label>
        <label>Direção<select name="direction"><option value="">Todas</option><option value="DEBIT" @selected(request('direction')==='DEBIT')>Débito</option><option value="CREDIT" @selected(request('direction')==='CREDIT')>Crédito</option></select></label>
        <button class="button primary" type="submit">Filtrar</button><a class="button ghost" href="{{ route('reconciliation-v2.show',$session) }}">Limpar</a>
    </form>
</section>

<form method="post" action="{{ route('reconciliation-v2.matches.store',$session) }}">
@csrf
<div class="reconciliation-v2-grid section-gap">
    <section class="panel table-panel">
        <header class="panel-header"><div><h3>Títulos e parcelas</h3><p>Selecione valores disponíveis; vencimento pode estar fora do período.</p></div></header>
        <div class="table-wrap"><table><thead><tr>@can('reconciliation:manage')<th></th>@endcan<th>Título</th><th>Vencimento</th><th>Tipo</th><th>Valores</th>@can('reconciliation:manage')<th>Alocar</th>@endcan</tr></thead><tbody>
        @forelse($titles as $installment) @php($available=$titleAvailability[$installment->id])
            <tr>@can('reconciliation:manage')<td><input type="checkbox" name="title_installment_ids[]" value="{{ $installment->id }}" @disabled($available['available']==='0.00') aria-label="Selecionar parcela {{ $installment->id }}"></td>@endcan<td><strong>{{ $installment->financialTitle->party_name ?: 'Título #'.$installment->financial_title_id }}</strong><small>{{ $installment->financialTitle->document_number ?: 'Sem documento' }} · parcela {{ $installment->installment_number }}</small><small>Status financeiro: {{ $installment->financialTitle->status->value }} · liquidações: {{ $installment->financialTitle->settlements->count() }}</small></td><td>{{ $installment->due_date->format('d/m/Y') }}</td><td><span class="badge {{ $installment->financialTitle->type->value==='PAYABLE'?'danger':'success' }}">{{ $installment->financialTitle->type->value }}</span></td><td><strong>R$ {{ number_format((float)$available['total'],2,',','.') }}</strong><small>Conciliado R$ {{ number_format((float)$available['allocated'],2,',','.') }} · disponível R$ {{ number_format((float)$available['available'],2,',','.') }}</small><span class="badge neutral">{{ $available['status'] }}</span></td>@can('reconciliation:manage')<td><input class="allocation-input" name="title_allocations[{{ $installment->id }}]" value="{{ $available['available'] }}" inputmode="decimal" @disabled($available['available']==='0.00')></td>@endcan</tr>
        @empty<tr><td colspan="6"><div class="empty-state"><strong>Nenhuma parcela conciliável</strong><p>Somente títulos modernos com conta explícita aparecem aqui.</p></div></td></tr>@endforelse
        </tbody></table></div>@include('partials.pagination',['paginator'=>$titles])
    </section>

    <section class="panel table-panel">
        <header class="panel-header"><div><h3>Transações bancárias</h3><p>Somente fatos da conta e do período da sessão.</p></div></header>
        <div class="table-wrap"><table><thead><tr>@can('reconciliation:manage')<th></th>@endcan<th>Data</th><th>Descrição original</th><th>Direção</th><th>Valores</th>@can('reconciliation:manage')<th>Alocar</th>@endcan</tr></thead><tbody>
        @forelse($transactions as $transaction) @php($available=$transactionAvailability[$transaction->id])
            <tr>@can('reconciliation:manage')<td><input type="checkbox" name="bank_transaction_ids[]" value="{{ $transaction->id }}" @disabled($available['available']==='0.00') aria-label="Selecionar transação {{ $transaction->id }}"></td>@endcan<td>{{ $transaction->transaction_date->format('d/m/Y') }}<small>{{ $transaction->external_id }}</small></td><td><strong>{{ $transaction->description_original }}</strong><small>{{ $transaction->bank_reference }}</small></td><td><span class="badge {{ $transaction->direction->value==='CREDIT'?'success':'danger' }}">{{ $transaction->direction->value }}</span></td><td><strong>R$ {{ number_format((float)$available['total'],2,',','.') }}</strong><small>Conciliado R$ {{ number_format((float)$available['allocated'],2,',','.') }} · disponível R$ {{ number_format((float)$available['available'],2,',','.') }}</small><span class="badge neutral">{{ $available['status'] }}</span></td>@can('reconciliation:manage')<td><input class="allocation-input" name="transaction_allocations[{{ $transaction->id }}]" value="{{ $available['available'] }}" inputmode="decimal" @disabled($available['available']==='0.00')></td>@endcan</tr>
        @empty<tr><td colspan="6"><div class="empty-state"><strong>Nenhuma transação no período</strong><p>Importe fatos bancários antes de conciliar.</p>@can('reconciliation:manage')<a class="button primary" href="{{ route('banking.create') }}">Importar extrato OFX</a>@endcan</div></td></tr>@endforelse
        </tbody></table></div>@include('partials.pagination',['paginator'=>$transactions])
    </section>
</div>
@can('reconciliation:manage')<div class="match-submit"><p>O servidor validará conta, período, direção, moeda, disponibilidade e igualdade exata dos totais.</p><button class="button primary" type="submit">Confirmar match manual</button></div>@endcan
</form>

<section class="panel table-panel section-gap"><header class="panel-header"><div><h3>Histórico persistente</h3><p>Matches confirmados e desfeitos permanecem auditáveis.</p></div></header><div class="table-wrap"><table><thead><tr><th>Match</th><th>Status</th><th>Método</th><th>Ator</th><th>Composição</th><th>Data</th><th></th></tr></thead><tbody>
@forelse($history as $match)<tr><td><strong>#{{ $match->id }}</strong><small>{{ $match->correlation_id }}</small></td><td><span class="badge {{ $match->status->value==='CONFIRMED'?'success':'neutral' }}">{{ $match->status->value }}</span></td><td>{{ $match->method->value }}</td><td>{{ $match->confirmer?->nome ?: $match->confirmer?->username ?: '#'.$match->confirmed_by }}</td><td>{{ $match->titleAllocations->count() }} título(s) ↔ {{ $match->transactionAllocations->count() }} transação(ões)</td><td>{{ $match->confirmed_at->format('d/m/Y H:i') }}</td><td><a class="row-action" href="{{ route('reconciliation-v2.matches.show',[$session,$match]) }}">→</a></td></tr>@empty<tr><td colspan="7"><div class="empty-state small"><strong>Nenhum match confirmado</strong></div></td></tr>@endforelse
</tbody></table></div>@include('partials.pagination',['paginator'=>$history])</section>

@if(config('reconciliation.closing_enabled', false))
@can('reconciliation:view')
<section class="panel section-gap"><header class="panel-header"><div><h3>Fechamento</h3><p>Transforma o estado atual em um fechamento histórico reproduzível e auditável.</p></div></header>
@if(in_array($session->status->value, ['OPEN','IN_REVIEW','REOPENED'], true))
<div class="form-actions">
@can('reconciliation:close')<a class="button primary" href="{{ route('reconciliation-v2.closure.create',$session) }}">Preparar fechamento →</a>@endcan
@if($latestClosure)<a class="button ghost" href="{{ route('reconciliation-v2.closure.history',$session) }}">Ver histórico de fechamentos →</a>@endif
</div>
@else
<p>Fechamento #{{ $latestClosure->sequence_number }} em {{ $latestClosure->closed_at->format('d/m/Y H:i:s') }} por {{ $latestClosure->closedByUser?->nome ?: $latestClosure->closedByUser?->username ?: '#'.$latestClosure->closed_by }}</p>
<p><small title="{{ $latestClosure->closure_hash }}">hash {{ substr($latestClosure->closure_hash, 0, 16) }}…</small></p>
<div class="form-actions">
<a class="button ghost" href="{{ route('reconciliation-v2.closure.show',[$session,$latestClosure]) }}">Ver fechamento →</a>
<a class="button ghost" href="{{ route('reconciliation-v2.closure.history',$session) }}">Ver histórico →</a>
</div>
@endif
</section>
@endcan
@endif
@endsection
