@extends('layouts.app')
@section('title', 'Conciliação')
@section('section', 'Conciliação')
@section('page-title', 'Conciliação')

@php
    use App\Domain\Financial\Money;
    $brlSinal = fn (int $cents) => ($cents < 0 ? '-' : '').'R$ '.number_format((float) Money::fromCents(abs($cents)), 2, ',', '.');
@endphp

@section('content')

<div class="page-heading">
    <div>
        <span class="eyebrow">Conciliação</span>
        <h2>Conciliação</h2>
        <p>Entradas, saídas e saldo corrido por conta. Abra no começo do mês e vá atualizando; feche quando o período estiver conferido.</p>
    </div>
    <div class="heading-actions">
        @can('reconciliation:manage')
            <a class="button ghost" href="{{ route('manual-movements.create') }}">+ Adicionar movimento</a>
            <a class="button primary" href="{{ route('period-statements.create') }}">Nova conciliação</a>
        @endcan
    </div>
</div>

{{--
    Duas coisas diferentes moram nesta tela e confundir uma com a outra é fácil:
    ADICIONAR movimento é lançar um PIX que aconteceu; NOVA CONCILIAÇÃO abre um
    período novo para acompanhar. Por isso os dois atalhos vêm rotulados.
--}}
<section class="shortcut-strip">
    <div>
        <strong>Lançou um PIX, uma tarifa ou um rendimento?</strong>
        <small>Registre como movimento manual — ele entra no saldo do período sem criar título nenhum.</small>
    </div>
    <a class="button ghost" href="{{ route('manual-movements.index') }}">Ver movimentos manuais →</a>
</section>

<section class="panel table-panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Período</th>
                    <th>Banco</th>
                    <th>Conta</th>
                    <th>Estado</th>
                    <th class="align-right">Movimentos</th>
                    <th class="align-right">Entradas</th>
                    <th class="align-right">Saídas</th>
                    <th class="align-right">Saldo</th>
                    <th>Última atualização</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($statements as $s)
                    <tr>
                        <td>{{ $s->period_start->format('d/m/Y') }} a {{ $s->period_end->format('d/m/Y') }}</td>
                        <td>{{ $s->account_bank ?: '—' }}</td>
                        <td><strong>{{ $s->account_name }}</strong></td>
                        <td><span class="badge {{ $s->isOpen() ? 'info' : 'success' }}">{{ $s->status->label() }}</span></td>
                        <td class="align-right">{{ $s->line_count }}</td>
                        <td class="align-right">{{ $brlSinal($s->total_in_cents) }}</td>
                        <td class="align-right">{{ $brlSinal($s->total_out_cents) }}</td>
                        <td class="align-right"><strong>{{ $brlSinal($s->closing_balance_cents) }}</strong></td>
                        <td>{{ ($s->last_synced_at ?? $s->generated_at)->format('d/m/Y H:i') }}</td>
                        <td class="inline-actions">
                            @can('reconciliation:manage')
                                <form method="post" action="{{ route('period-statements.destroy', $s) }}" class="inline-form"
                                      onsubmit="return confirm('Excluir esta conciliação?\n\nOs títulos, liquidações e movimentos manuais que ela resume NÃO são apagados — só este resumo.')">
                                    @csrf @method('delete')
                                    <button class="row-action danger" type="submit" aria-label="Excluir">×</button>
                                </form>
                            @endcan
                            <a class="row-action" href="{{ route('period-statements.show', $s) }}" aria-label="Abrir">→</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            <div class="empty-state">
                                <span>⌕</span>
                                <strong>Nenhuma conciliação aberta ainda</strong>
                                <p>Use "Nova conciliação" para começar a acompanhar uma conta neste período.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.pagination', ['paginator' => $statements])
</section>

@endsection
