@extends('layouts.app')
@section('title', 'Lote de importação #'.$batch->id)
@section('section', 'Extratos bancários')
@section('page-title', 'Lote de importação #'.$batch->id)

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">Lote auditável</span><h2>Importação #{{ $batch->id }}</h2><p>{{ $account?->nome ?? 'Conta '.$batch->account_id }} · {{ $batch->created_at?->format('d/m/Y H:i') }}</p></div>
    <div><a class="button ghost" href="{{ route('banking.index') }}">Voltar</a> @can('reconciliation:manage')<a class="button primary" href="{{ route('banking.create') }}">+ Importar outro</a>@endcan</div>
</div>

<section class="panel">
    <div class="stat-row">
        <div class="stat"><span>Status</span><strong>{{ $batch->status->value }}</strong></div>
        <div class="stat"><span>Formato</span><strong>{{ $batch->format }}</strong></div>
        <div class="stat"><span>Canal</span><strong>{{ $batch->channel }}</strong></div>
        <div class="stat"><span>Total lido</span><strong>{{ (int) $batch->total_items }}</strong></div>
        <div class="stat"><span>Novos</span><strong>{{ (int) $batch->imported_items }}</strong></div>
        <div class="stat"><span>Duplicados</span><strong>{{ (int) $batch->duplicate_items }}</strong></div>
        <div class="stat"><span>Recusados</span><strong>{{ (int) $batch->rejected_items }}</strong></div>
    </div>
    @if($batch->original_filename)<p><small>Arquivo: {{ $batch->original_filename }}</small></p>@endif
    @if($batch->failure_summary)<div class="alert error"><strong>{{ $batch->failure_code }}:</strong> {{ $batch->failure_summary }}</div>@endif
    <p><small>Correlação: {{ $batch->correlation_id }}</small></p>
</section>

<section class="panel table-panel section-gap">
    <header class="panel-header"><div><h3>Itens do extrato</h3><p>Resultado item a item, na ordem original do arquivo.</p></div></header>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Identificador</th><th>Resultado</th><th>Transação</th><th>Motivo</th></tr></thead><tbody>
    @forelse($items as $item)
        <tr>
            <td>{{ $item->position }}</td>
            <td><small>{{ $item->external_id ?: '—' }}</small></td>
            <td><span class="badge {{ $item->result === 'IMPORTED' ? 'success' : ($item->result === 'DUPLICATE' ? 'info' : 'danger') }}">{{ $item->result }}</span></td>
            <td>{{ $item->bank_transaction_id ? '#'.$item->bank_transaction_id : '—' }}</td>
            <td>{{ $item->error_message ?: '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="5"><div class="empty-state"><strong>Nenhum item registrado</strong><p>Este lote não possui itens detalhados.</p></div></td></tr>
    @endforelse
    </tbody></table></div>
    @include('partials.pagination',['paginator'=>$items])
</section>
@endsection
