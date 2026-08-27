@extends('layouts.app')
@section('title', 'Movimentos')
@section('section', 'Financeiro')
@section('page-title', 'Movimentos')
@section('content')
<div class="page-heading"><div><span class="eyebrow">Movimentação financeira</span><h2>Movimentos</h2><p>Entradas, saídas e saldos registrados por conta.</p></div>@can('payments')<a class="button primary" href="{{ route('movements.create') }}">+ Novo movimento</a>@endcan</div>
<section class="panel table-panel"><form class="filter-bar" method="get"><label class="search"><span>⌕</span><input name="q" value="{{ $search }}" placeholder="Buscar descrição, operação ou conta"></label><button class="button primary">Buscar</button><a class="button ghost" href="{{ route('movements.index') }}">Limpar</a></form><div class="table-wrap"><table><thead><tr><th>Data</th><th>Descrição</th><th>Conta</th><th>Operação</th><th class="align-right">Valor</th><th>Conciliação</th></tr></thead><tbody>
@forelse($records as $record) @php($incoming = $record->operacao!=='saida')<tr><td>{{ $record->dateLabel('data_referencia') }}</td><td><strong>{{ $record->descricao ?: 'Movimento financeiro' }}</strong><small>Registro #{{ $record->id }}</small></td><td>{{ $record->id_conta }}</td><td><span class="badge {{ $incoming ? 'success' : 'neutral' }}">{{ ucfirst($record->operacao) }}</span></td><td class="align-right"><strong class="money {{ $incoming ? 'in' : 'out' }}">{{ $incoming ? '+' : '−' }} R$ {{ number_format((float)$record->valor, 2, ',', '.') }}</strong></td><td><a class="row-action" href="{{ route('movements.show',$record) }}">→</a></td></tr>@empty<tr><td colspan="6"><div class="empty-state"><strong>Nenhum movimento encontrado</strong><p>Tente alterar os filtros.</p></div></td></tr>@endforelse
</tbody></table></div>@include('partials.pagination', ['paginator'=>$records])</section>
@endsection
