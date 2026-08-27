@extends('layouts.app')
@section('title', 'Quarentena da sincronização')
@section('section', 'Sincronização')
@section('page-title', 'Quarentena da sincronização')

@section('content')

@if(session('success'))
    <div class="alert alert-success" style="margin-bottom:12px">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" style="margin-bottom:12px">{{ $errors->first() }}</div>
@endif

<div class="page-heading">
    <div>
        <span class="eyebrow">Sincronização</span>
        <h2>Títulos em quarentena</h2>
        <p>
            A origem tentou alterar um campo protegido de um título que já está liquidado ou cancelado aqui.
            O Gestão recusou — histórico financeiro não se reescreve por reenvio — e guardou o caso aqui em vez
            de derrubar a sincronização. Todo o resto do lote foi aplicado normalmente.
        </p>
    </div>
</div>

<div class="alert alert-info" style="margin-bottom:14px">
    Isto <strong>não</strong> é falha do sistema. É divergência entre duas verdades: a da origem, que foi editada,
    e a do Gestão, que já registrou o dinheiro se movendo. Só uma pessoa pode decidir qual vale.
</div>

<section class="panel table-panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ORIGEM</th>
                    <th>ID NA ORIGEM</th>
                    <th>CONFLITO</th>
                    <th class="align-right">VEZES</th>
                    <th>PRIMEIRA</th>
                    <th>ÚLTIMA</th>
                    <th>RESOLVER</th>
                </tr>
            </thead>
            <tbody>
                @forelse($abertos as $c)
                    <tr>
                        <td>{{ $c->label() }}</td>
                        <td>
                            <strong>{{ $c->external_id }}</strong>
                            @if($c->financial_title_id)
                                <small>título #{{ $c->financial_title_id }}</small>
                            @endif
                        </td>
                        <td>{{ $c->reason }}</td>
                        {{-- Insistência é sinal: 300 repetições é a origem afirmando todo dia que o Gestão está errado. --}}
                        <td class="align-right"><strong>{{ $c->occurrences }}</strong></td>
                        <td>{{ $c->first_seen_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $c->last_seen_at->format('d/m/Y H:i') }}</td>
                        <td>
                            @can('reconciliation:manage')
                                <form method="post" action="{{ route('sync.conflicts.resolve', $c) }}"
                                      class="inline-form" style="display:flex;gap:6px;align-items:center">
                                    @csrf
                                    <input type="text" name="note" maxlength="250" required
                                           placeholder="O que foi decidido?"
                                           style="height:32px;padding:0 9px;border:1px solid #d9dfe8;border-radius:7px;min-width:190px">
                                    <button class="button ghost" type="submit">Resolver</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <span>✓</span>
                                <strong>Nenhum conflito em aberto</strong>
                                <p>A sincronização está aplicando tudo que a origem manda.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($abertos->hasPages())
        <div class="pagination">{{ $abertos->links() }}</div>
    @endif
</section>

@if($resolvidos > 0)
    <p style="margin-top:12px;color:#8993a4;font-size:11px">
        {{ $resolvidos }} conflito(s) já resolvido(s). Se a origem voltar a enviar a mesma alteração proibida,
        o conflito reabre sozinho — resolver não silencia o aviso, só registra a decisão.
    </p>
@endif

@endsection
