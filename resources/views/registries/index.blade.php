@extends('layouts.app')
@section('title', $title)
@section('section', 'Cadastros')
@section('page-title', $title)
@section('content')
<div class="page-heading"><div><span class="eyebrow">Cadastros auxiliares</span><h2>{{ $title }}</h2><p>Consulta centralizada dos registros existentes no Gestão.</p></div></div>
<section class="panel table-panel"><form class="filter-bar" method="get"><label class="search"><span>⌕</span><input name="q" value="{{ $search }}" placeholder="Buscar por nome"></label><button class="button primary">Buscar</button><a class="button ghost" href="{{ url()->current() }}">Limpar</a></form><div class="table-wrap"><table><thead><tr><th>Identificação</th>@if($kind !== 'accounts')<th>Documento</th><th>Contato</th><th>Localização</th>@else<th>Descrição detalhada</th><th>Dados bancários</th>@endif<th>Status</th></tr></thead><tbody>
@forelse($records as $record)<tr><td><strong>{{ $record->nome ?? $record->nome_fantasia ?? $record->razao_social }}</strong><small>#{{ $record->id }}@if($record->razao_social && $record->razao_social !== $record->nome_fantasia) · {{ $record->razao_social }}@endif</small></td>@if($kind !== 'accounts')<td>{{ $record->cnpj ?: $record->cpf ?: '—' }}</td><td>{{ $record->email ?: '—' }}<small>{{ $record->telefone ?: $record->celular }}</small></td><td>{{ $record->cidade ?: '—' }}{{ $record->estado ? ' / '.$record->estado : '' }}</td>@else<td>{{ $record->nome_detalhado }}</td><td>{{ $record->dados_completos }}</td>@endif<td><span class="badge success">Ativo</span></td></tr>@empty<tr><td colspan="5"><div class="empty-state"><strong>Nenhum registro encontrado</strong><p>Tente buscar novamente.</p></div></td></tr>@endforelse
</tbody></table></div>@include('partials.pagination', ['paginator'=>$records])</section>
@endsection
