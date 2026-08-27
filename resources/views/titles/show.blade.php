@extends('layouts.app')
@section('title', 'Título '.$title->external_id)
@section('section', 'Títulos')
@section('page-title', 'Título '.($title->document_number ?: $title->external_id))

@php
    use App\Domain\Financial\Money;
    $brl = fn (int $cents) => number_format(((float) Money::fromCents(abs($cents))), 2, ',', '.');
    $remaining = $title->remainingCents();
    $isPayable = $title->type->value === 'PAYABLE';
@endphp

@section('content')
<a class="back-link" href="{{ route('titles.index') }}">← Voltar</a>

<header class="record-hero">
    <div>
        <span class="eyebrow">{{ $isPayable ? 'A pagar' : 'A receber' }} · origem {{ $title->sourceSystem?->code }}</span>
        <h2>{{ $title->party_name ?: 'Título #'.$title->id }}</h2>
        <p>Vence em {{ $title->due_date->format('d/m/Y') }} · emitido em {{ $title->issue_date->format('d/m/Y') }}</p>
        <p><small>Identificador na origem: <strong>{{ $title->external_id }}</strong>{{ $account ? ' · '.$account->nome : '' }}</small></p>
    </div>
    <div class="record-value">
        @if($title->status->value === 'SETTLED')<span class="badge success">{{ $isPayable ? 'PAGO' : 'RECEBIDO' }}</span>
        @elseif($title->status->value === 'PARTIALLY_SETTLED')<span class="badge info">PARCIAL</span>
        @elseif($title->status->value === 'CANCELLED')<span class="badge neutral">CANCELADO</span>
        @else<span class="badge warning">EM ABERTO</span>@endif
        <strong>R$ {{ number_format((float) $title->total_amount, 2, ',', '.') }}</strong>
        <small>{{ $remaining > 0 ? 'Em aberto R$ '.$brl($remaining) : 'Totalmente realizado' }}</small>
    </div>
</header>

@if($errors->any())
    <div class="alert error"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

@can('payments')
@if($remaining > 0 && $title->status->value !== 'CANCELLED')
<section class="panel">
    <header class="panel-header"><div><h3>Marcar como {{ $isPayable ? 'pago' : 'recebido' }}</h3><p>Registra a realização no Gestão. Não altera o sistema de origem e não concilia — conciliar é ligar ao extrato depois.</p></div></header>
    <form method="post" action="{{ route('titles.settle', $title) }}" class="form-grid">
        @csrf
        <label class="field"><span>Data do {{ $isPayable ? 'pagamento' : 'recebimento' }}</span><input type="date" name="settlement_date" value="{{ now()->toDateString() }}" required></label>
        @if($title->installments->count() > 1)
            <label class="field"><span>Parcela</span><select name="installment_id" required>
                @foreach($title->installments as $installment)
                    @if($installment->remainingCents() > 0)
                        <option value="{{ $installment->id }}">Parcela {{ $installment->installment_number }} — vence {{ $installment->due_date->format('d/m/Y') }} — em aberto R$ {{ $brl($installment->remainingCents()) }}</option>
                    @endif
                @endforeach
            </select></label>
        @else
            <input type="hidden" name="installment_id" value="{{ $title->installments->first()?->id }}">
        @endif
        <label class="field"><span>Valor <small>(vazio = saldo total)</small></span><input name="amount" inputmode="decimal" placeholder="{{ Money::fromCents($remaining) }}"></label>
        <div class="form-actions"><button class="button primary" type="submit">Confirmar {{ $isPayable ? 'pagamento' : 'recebimento' }}</button></div>
    </form>
</section>
@endif
@endcan

<section class="panel table-panel section-gap">
    <header class="panel-header"><div><h3>Parcelas</h3></div></header>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Vencimento</th><th class="align-right">Valor</th><th class="align-right">Em aberto</th><th>Situação</th></tr></thead><tbody>
    @forelse($title->installments as $installment)
        <tr>
            <td>{{ $installment->installment_number }}</td>
            <td>{{ $installment->due_date->format('d/m/Y') }}</td>
            <td class="align-right">R$ {{ number_format((float) $installment->amount, 2, ',', '.') }}</td>
            <td class="align-right">{{ $installment->remainingCents() > 0 ? 'R$ '.$brl($installment->remainingCents()) : '—' }}</td>
            <td><span class="badge {{ $installment->remainingCents() > 0 ? 'warning' : 'success' }}">{{ $installment->remainingCents() > 0 ? 'EM ABERTO' : 'REALIZADA' }}</span></td>
        </tr>
    @empty<tr><td colspan="5"><div class="empty-state"><strong>Sem parcelas</strong></div></td></tr>@endforelse
    </tbody></table></div>
</section>

<section class="panel table-panel section-gap">
    <header class="panel-header"><div><h3>Realizações</h3><p>Pagamentos ou recebimentos registrados neste título.</p></div></header>
    <div class="table-wrap"><table><thead><tr><th>Data</th><th>Tipo</th><th class="align-right">Valor</th><th>Origem</th><th>Situação</th></tr></thead><tbody>
    @forelse($title->settlements->sortBy('settlement_date') as $settlement)
        <tr>
            <td>{{ $settlement->settlement_date->format('d/m/Y') }}</td>
            <td>{{ $settlement->type->value === 'PAYMENT' ? 'Pagamento' : 'Recebimento' }}</td>
            <td class="align-right">R$ {{ number_format((float) $settlement->amount, 2, ',', '.') }}</td>
            <td><small>{{ $settlement->external_id ?: ($settlement->created_by ? 'Manual (usuário #'.$settlement->created_by.')' : '—') }}</small></td>
            <td><span class="badge success">{{ $settlement->status->value }}</span></td>
        </tr>
    @empty
        <tr><td colspan="5"><div class="empty-state"><strong>Nenhuma realização</strong><p>O título ainda não foi pago nem recebido.</p></div></td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endsection
