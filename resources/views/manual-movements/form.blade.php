@extends('layouts.app')

@php
    use App\Domain\Financial\Money;
    $novo = ! $record->exists;
    $titulo = $novo ? 'Novo movimento' : 'Corrigir movimento';
    $valorAtual = old('amount', $record->exists ? number_format((float) $record->amount, 2, ',', '.') : '');
    $direcaoAtual = old('direction', $record->direction?->value ?? 'IN');
@endphp

@section('title', $titulo)
@section('section', 'Conciliação')
@section('page-title', $titulo)

@section('content')

<div class="page-heading">
    <div>
        <span class="eyebrow">Conciliação · Movimentos manuais</span>
        <h2>{{ $titulo }}</h2>
        <p>Dinheiro que entrou ou saiu da conta sem passar por Contas a pagar ou Contas a receber — PIX, tarifa, rendimento, transferência, ajuste.</p>
    </div>
</div>

{{--
    A dúvida "isso vai mexer nos títulos?" é a primeira que aparece, e a tela
    responde antes de a pessoa perguntar.
--}}
<div class="alert alert-info" style="margin-bottom:14px">
    Este lançamento é independente dos títulos: não marca nada como pago, não cria baixa
    e não altera os sistemas de origem. Ele entra no movimento do período da conta escolhida.
</div>

<form method="post" action="{{ $novo ? route('manual-movements.store') : route('manual-movements.update', $record) }}">
    @csrf
    @unless($novo) @method('put') @endunless

    <section class="panel">
        <div class="form-grid">
            <label class="field">
                <span>Conta</span>
                <select name="account_id" required>
                    <option value="">— Selecione —</option>
                    @foreach($contas as $c)
                        <option value="{{ $c->id }}" @selected((string) old('account_id', $record->account_id) === (string) $c->id)>
                            {{ $c->nome }}@if($c->banco) — {{ $c->banco }}@endif
                        </option>
                    @endforeach
                </select>
            </label>

            {{--
                Vazio é a resposta certa quase sempre: o dinheiro passou pela
                conta de sempre da empresa. Preencher só faz sentido quando
                fugiu do padrão — transferência entre contas do grupo, depósito
                que caiu em outro banco. É o que os históricos das planilhas
                mostram ("Depositou BB 30/06/2026").
            --}}
            <label class="field">
                <span>Conta bancária <small>opcional</small></span>
                <select name="bank_account_id">
                    <option value="">— Conta padrão da empresa —</option>
                    @foreach($bancos as $b)
                        <option value="{{ $b->id }}" @selected((string) old('bank_account_id', $record->bank_account_id) === (string) $b->id)>
                            {{ $b->fullLabel() }}@if($b->is_default) (padrão)@endif
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="field">
                <span>Data</span>
                <input type="date" name="movement_date" required
                       value="{{ old('movement_date', $record->movement_date?->toDateString() ?? now()->toDateString()) }}">
            </label>

            <label class="field">
                <span>Nº do documento <small>opcional</small></span>
                <input type="text" name="document_number" maxlength="120"
                       placeholder="NF.291, boleto, recibo"
                       value="{{ old('document_number', $record->document_number) }}">
            </label>

            <fieldset class="field field-choice">
                <legend>Tipo</legend>
                <div class="choice-row">
                    @foreach($direcoes as $direcao)
                        <label class="choice {{ $direcaoAtual === $direcao->value ? 'choice-on' : '' }}">
                            <input type="radio" name="direction" value="{{ $direcao->value }}"
                                   @checked($direcaoAtual === $direcao->value) required>
                            <span>{{ $direcao->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <label class="field">
                <span>Valor</span>
                <input type="text" name="amount" required inputmode="decimal" placeholder="0,00"
                       value="{{ $valorAtual }}">
            </label>

            <label class="field field-wide">
                <span>Histórico</span>
                <input type="text" name="history" required maxlength="250"
                       placeholder="PIX recebido - Cliente"
                       value="{{ old('history', $record->history) }}">
            </label>

            @if($categorias->isNotEmpty())
                <label class="field">
                    <span>Categoria <small>opcional</small></span>
                    <select name="category_id">
                        <option value="">— Nenhuma —</option>
                        @foreach($categorias as $cat)
                            <option value="{{ $cat->id }}" @selected((string) old('category_id', $record->category_id) === (string) $cat->id)>{{ $cat->nome }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="field field-wide">
                <span>Observação <small>opcional</small></span>
                <textarea name="notes" rows="2" maxlength="2000">{{ old('notes', $record->notes) }}</textarea>
            </label>
        </div>

        <div class="form-actions">
            <a class="button ghost" href="{{ route('manual-movements.index') }}">Cancelar</a>
            <button class="button primary" type="submit">{{ $novo ? 'Registrar movimento' : 'Salvar correção' }}</button>
        </div>
    </section>
</form>

@endsection
