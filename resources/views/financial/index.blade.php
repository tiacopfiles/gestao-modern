@extends('layouts.app')
@section('title', $title)
@section('section', 'Financeiro')
@section('page-title', $title)

@section('content')
<div class="page-heading"><div><span class="eyebrow">Operação financeira</span><h2>{{ $title }}</h2><p>Consulte títulos, vencimentos, valores e situações cadastradas.</p></div>@can('payments')<a class="button primary" href="{{ route($kind==='payables'?'payables.create':'receivables.create') }}">+ Novo título</a>@endcan</div>
<section class="summary-strip"><div><span>Registros encontrados</span><strong>{{ number_format($summary['count'], 0, ',', '.') }}</strong></div><div><span>Valor consolidado</span><strong>R$ {{ number_format($summary['value'], 2, ',', '.') }}</strong></div><div><span>Origem</span><strong>Gestão legado</strong></div></section>
<section class="panel table-panel">
    <form class="filter-bar" method="get">
        <label class="search"><span>⌕</span><input name="q" value="{{ $search }}" placeholder="Buscar documento, fornecedor ou situação"></label>
        <label>De<input type="date" name="from" value="{{ request('from') }}"></label>
        <label>Até<input type="date" name="to" value="{{ request('to') }}"></label>
        <button class="button primary" type="submit">Filtrar</button><a class="button ghost" href="{{ url()->current() }}">Limpar</a>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Vencimento</th><th>{{ $kind === 'payables' ? 'Fornecedor' : 'Cliente / origem' }}</th><th>Documento</th><th>Categoria</th><th>Conta</th><th class="align-right">Valor</th><th>Situação</th><th></th></tr></thead><tbody>
    @forelse($records as $record)
        @php($rawDue = $record->getRawOriginal('data_vencimento'))
        @php($overdue = is_string($rawDue) && $rawDue > '0000-00-00' && $rawDue < now()->toDateString())
        <tr><td><span class="date-primary">{{ $record->dateLabel('data_vencimento') }}</span>@if($overdue && (string)$record->situacao!=='4')<small class="danger-text">Vencido</small>@endif</td><td><strong>{{ $lookups['parties'][$kind === 'payables' ? $record->fornecedor : $record->cliente] ?? ($kind === 'payables' ? $record->fornecedor : ($record->cliente ?: $record->fornecedor)) }}</strong><small>Origem: {{ $record->tipo_lancamento ?: 'Gestão' }}</small></td><td>{{ $record->numero_doc }}</td><td>{{ $lookups['categories'][$record->categoria] ?? $record->categoria }}</td><td>{{ $lookups['accounts'][$record->conta] ?? $record->conta }}</td><td class="align-right"><strong>R$ {{ number_format((float)$record->valor_total, 2, ',', '.') }}</strong></td><td><span class="badge {{ (string)$record->situacao==='4'?'success':($overdue?'danger':'warning') }}">{{ $lookups['statuses'][$record->situacao] ?? ($record->situacao ?: 'Pendente') }}</span></td><td><a class="row-action" href="{{ $kind === 'payables' ? route('payables.show', $record) : route('receivables.show', $record) }}" aria-label="Visualizar">→</a></td></tr>
    @empty
        {{--
            Esta tela le a base LEGADA (`lancamentos` / `recebimentos`). Num
            ambiente alimentado por sincronizacao das origens, ela fica vazia por
            construcao, e os titulos reais estao em Titulos. Sem dizer isso aqui,
            a tela vazia parece defeito — foi exatamente o que aconteceu.
        --}}
        <tr><td colspan="8">
            <div class="empty-state">
                <span>⌕</span>
                <strong>Nenhum título nesta base</strong>
                @if(config('reconciliation.v2_enabled', false))
                    <p>
                        Esta tela mostra a <strong>base legada</strong> deste banco, que está vazia neste ambiente.
                        Os títulos sincronizados de Contas a Pagar e Contas a Receber ficam em
                        <a href="{{ route('titles.index', ['type' => $kind === 'payables' ? 'PAYABLE' : 'RECEIVABLE']) }}">
                            {{ $kind === 'payables' ? 'Contas a pagar' : 'Contas a receber' }}
                        </a>.
                    </p>
                @else
                    <p>Tente alterar os filtros selecionados.</p>
                @endif
            </div>
        </td></tr>
    @endforelse
    </tbody></table></div>
    @include('partials.pagination', ['paginator' => $records])
</section>
@endsection
