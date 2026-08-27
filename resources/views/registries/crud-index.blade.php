@extends('layouts.app')
@section('content')

@php
    // Cadastros que vêm da sincronização são SOMENTE CONSULTA aqui.
    //
    // Quem cadastra fornecedor, cliente, categoria, tipo, situação e centro de
    // custo é o sistema de origem. Permitir editar dos dois lados criaria duas
    // verdades sobre o mesmo fornecedor, e a próxima sincronização decidiria a
    // briga sozinha — sem ninguém saber que houve briga.
    //
    // `contas` fica editável: o campo `banco` é do Gestão, não vem da origem.
    $sincronizadoDaOrigem = in_array($kind, ['clientes', 'fornecedores', 'categorias', 'tipos', 'situacoes', 'centros-custo'], true);
@endphp

<div class="page-heading">
    <div>
        <span class="eyebrow">{{ $sincronizadoDaOrigem ? 'Cadastro da origem' : 'Cadastros auxiliares' }}</span>
        <h2>{{ $title }}</h2>
        <p>
            @if($sincronizadoDaOrigem)
                Sincronizado de Contas a Pagar / Contas a Receber. Somente consulta.
            @else
                Dados utilizados nas operações financeiras.
            @endif
        </p>
    </div>
    @unless($sincronizadoDaOrigem)
        <a class="button primary" href="{{ route('registries.create',$kind) }}">+ Novo cadastro</a>
    @endunless
</div>

@if($sincronizadoDaOrigem)
    <div class="alert" style="margin-bottom:14px;border-color:#cfe0fd;background:#f5f8ff;color:#24457e">
        Este cadastro é mantido nos sistemas de origem e atualizado pela sincronização.
        Alterar por aqui seria desfeito na próxima leitura — por isso a edição está desligada.
    </div>
@endif

<section class="panel table-panel">
    <form class="filter-bar">
        <label class="search"><span>⌕</span><input name="q" value="{{ $search }}" placeholder="Buscar"></label>
        <button class="button primary">Buscar</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    @foreach($searchable as $c)<th>{{ str_replace('_',' ',$c) }}</th>@endforeach
                    @unless($sincronizadoDaOrigem)<th></th>@endunless
                </tr>
            </thead>
            <tbody>
                @forelse($records as $r)
                    <tr>
                        @foreach($searchable as $c)<td>{{ $r->{$c} ?: '—' }}</td>@endforeach
                        @unless($sincronizadoDaOrigem)
                            <td>
                                <div class="inline-actions">
                                    <a class="row-action" href="{{ route('registries.edit',[$kind,$r->id]) }}">✎</a>
                                    <form method="post" action="{{ route('registries.destroy',[$kind,$r->id]) }}" onsubmit="return confirm('Excluir cadastro?')">
                                        @csrf @method('delete')
                                        <button class="row-action danger" type="submit">×</button>
                                    </form>
                                </div>
                            </td>
                        @endunless
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($searchable) + ($sincronizadoDaOrigem ? 0 : 1) }}">
                            <div class="empty-state"><strong>Nenhum cadastro encontrado</strong></div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.pagination',['paginator'=>$records])
</section>
@endsection
