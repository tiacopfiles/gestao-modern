@extends('layouts.app')
@section('title', 'Conciliação persistente')
@section('section', 'Conciliação v2')
@section('page-title', 'Conciliação persistente')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">Operação manual auditável</span><h2>Sessões de conciliação v2</h2><p>Relações persistentes entre títulos modernos e fatos bancários, sem baixa automática.</p></div>
    @can('reconciliation:manage')<a class="button primary" href="{{ route('reconciliation-v2.create') }}">+ Nova sessão</a>@endcan
</div>
<div class="alert warning-note"><strong>Fluxo paralelo:</strong> esta tela não substitui a conciliação antiga e não altera títulos, liquidações ou transações bancárias.</div>
<section class="panel table-panel">
    <form class="filter-bar" method="get">
        <label>Conta<select name="account_id"><option value="">Todas</option>@foreach($accounts as $item)<option value="{{ $item->id }}" @selected((string)request('account_id')===(string)$item->id)>{{ $item->nome }}</option>@endforeach</select></label>
        <label>Status<select name="status"><option value="">Todos</option><option value="OPEN" @selected(request('status')==='OPEN')>Aberta</option><option value="IN_REVIEW" @selected(request('status')==='IN_REVIEW')>Em revisão</option></select></label>
        <button class="button primary" type="submit">Filtrar</button><a class="button ghost" href="{{ route('reconciliation-v2.index') }}">Limpar</a>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Período</th><th>Conta</th><th>Status</th><th>Criada por</th><th>Correlação</th><th></th></tr></thead><tbody>
    @forelse($records as $record)
        <tr><td><strong>{{ $record->period_start->format('d/m/Y') }} — {{ $record->period_end->format('d/m/Y') }}</strong><small>Sessão #{{ $record->id }}</small></td><td>{{ $accountNames[$record->account_id] ?? 'Conta '.$record->account_id }}</td><td><span class="badge {{ $record->status->value==='IN_REVIEW'?'info':'warning' }}">{{ $record->status->value==='IN_REVIEW'?'EM REVISÃO':'ABERTA' }}</span></td><td>{{ $record->creator?->nome ?: $record->creator?->username ?: '#'.$record->created_by }}</td><td><small>{{ $record->correlation_id }}</small></td><td><a class="row-action" href="{{ route('reconciliation-v2.show',$record) }}" aria-label="Abrir sessão">→</a></td></tr>
    @empty<tr><td colspan="6"><div class="empty-state"><strong>Nenhuma sessão v2</strong><p>Crie uma sessão para uma conta e período autorizados.</p></div></td></tr>@endforelse
    </tbody></table></div>
    @include('partials.pagination',['paginator'=>$records])
</section>
@endsection
