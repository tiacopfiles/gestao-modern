@extends('layouts.app')
@section('title', 'Extratos bancários')
@section('section', 'Extratos bancários')
@section('page-title', 'Fatos bancários importados')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">Fase 3 — ingestão bancária</span><h2>Extratos bancários</h2><p>Fatos bancários importados, deduplicados por identidade. Nenhuma baixa de título é feita aqui.</p></div>
    @can('reconciliation:manage')<a class="button primary" href="{{ route('banking.create') }}">+ Importar extrato OFX</a>@endcan
</div>

<section class="panel table-panel">
    <form class="filter-bar" method="get">
        <label>Conta<select name="account_id"><option value="">Todas</option>@foreach($accounts as $item)<option value="{{ $item->id }}" @selected((string)request('account_id')===(string)$item->id)>{{ $item->nome }}</option>@endforeach</select></label>
        <label>Direção<select name="direction"><option value="">Todas</option><option value="CREDIT" @selected(request('direction')==='CREDIT')>Entrada</option><option value="DEBIT" @selected(request('direction')==='DEBIT')>Saída</option></select></label>
        <label>De<input type="date" name="from" value="{{ request('from') }}"></label>
        <label>Até<input type="date" name="to" value="{{ request('to') }}"></label>
        <label>Busca<input name="q" value="{{ request('q') }}" placeholder="Descrição, identificador ou documento"></label>
        <button class="button primary" type="submit">Filtrar</button><a class="button ghost" href="{{ route('banking.index') }}">Limpar</a>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Data</th><th>Conta</th><th>Descrição</th><th>Documento</th><th>Direção</th><th class="align-right">Valor</th><th>Identificador</th></tr></thead><tbody>
    @forelse($records as $record)
        <tr>
            <td><strong>{{ $record->transaction_date?->format('d/m/Y') }}</strong><small>Lote #{{ $record->import_batch_id }}</small></td>
            <td>{{ $accountNames[$record->account_id] ?? 'Conta '.$record->account_id }}</td>
            <td>{{ $record->description_original }}</td>
            <td>{{ $record->document_number ?: '—' }}</td>
            <td><span class="badge {{ $record->direction === 'CREDIT' ? 'success' : 'warning' }}">{{ $record->direction === 'CREDIT' ? 'ENTRADA' : 'SAÍDA' }}</span></td>
            <td class="align-right"><b class="money {{ $record->direction === 'CREDIT' ? 'in' : 'out' }}">{{ $record->direction === 'CREDIT' ? '+' : '−' }} R$ {{ number_format((float) $record->amount, 2, ',', '.') }}</b></td>
            <td><small>{{ $record->external_id }}</small></td>
        </tr>
    @empty
        <tr><td colspan="7"><div class="empty-state"><strong>Nenhum fato bancário</strong><p>Importe um extrato OFX para começar a conciliar.</p>@can('reconciliation:manage')<a class="button primary" href="{{ route('banking.create') }}">Importar extrato OFX</a>@endcan</div></td></tr>
    @endforelse
    </tbody></table></div>
    @include('partials.pagination',['paginator'=>$records])
</section>

<section class="panel table-panel section-gap">
    <header class="panel-header"><div><h3>Últimas importações</h3><p>Cada lote registra origem, arquivo e resultado item a item.</p></div></header>
    <div class="table-wrap"><table><thead><tr><th>Lote</th><th>Conta</th><th>Formato</th><th>Status</th><th class="align-right">Novos</th><th class="align-right">Duplicados</th><th class="align-right">Recusados</th><th></th></tr></thead><tbody>
    @forelse($batches as $batch)
        <tr>
            <td><strong>#{{ $batch->id }}</strong><small>{{ $batch->created_at?->format('d/m/Y H:i') }}</small></td>
            <td>{{ $accountNames[$batch->account_id] ?? 'Conta '.$batch->account_id }}</td>
            <td>{{ $batch->format }}<small>{{ $batch->channel }}</small></td>
            <td><span class="badge {{ $batch->status->value === 'COMPLETED' ? 'success' : ($batch->status->value === 'FAILED' ? 'danger' : 'info') }}">{{ $batch->status->value }}</span></td>
            <td class="align-right">{{ (int) $batch->imported_items }}</td>
            <td class="align-right">{{ (int) $batch->duplicate_items }}</td>
            <td class="align-right">{{ (int) $batch->rejected_items }}</td>
            <td><a class="row-action" href="{{ route('banking.batches.show',$batch) }}" aria-label="Abrir lote">→</a></td>
        </tr>
    @empty
        <tr><td colspan="8"><div class="empty-state"><strong>Nenhuma importação registrada</strong><p>Os lotes aparecem aqui após a primeira importação.</p></div></td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endsection
