@extends('layouts.app')
@section('title', 'Importar extrato OFX')
@section('section', 'Extratos bancários')
@section('page-title', 'Importar extrato OFX')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">Fase 3 — ingestão bancária</span><h2>Importar extrato OFX</h2><p>O arquivo é lido, deduplicado por identidade e registrado como lote auditável.</p></div>
    <a class="button ghost" href="{{ route('banking.index') }}">Voltar</a>
</div>

<div class="alert warning-note"><strong>Sem efeito financeiro:</strong> importar um extrato não baixa título nem cria liquidação. Os fatos bancários ficam disponíveis para conciliação.</div>

@if($errors->any())
    <div class="alert error">
        <strong>Não foi possível importar:</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<section class="panel">
    <form method="post" action="{{ route('banking.store') }}" enctype="multipart/form-data" class="form-grid">
        @csrf
        <label class="field">
            <span>Conta bancária</span>
            <select name="account_id" required>
                <option value="">Selecione a conta</option>
                @foreach($accounts as $item)
                    <option value="{{ $item->id }}" @selected((string)old('account_id')===(string)$item->id)>{{ $item->nome }}</option>
                @endforeach
            </select>
        </label>
        <label class="field">
            <span>Arquivo OFX</span>
            <input type="file" name="file" accept=".ofx,.OFX,text/plain,application/x-ofx" required>
            <small>Tamanho máximo: {{ number_format($maxBytes / 1024 / 1024, 1, ',', '.') }} MB.</small>
        </label>
        <div class="form-actions">
            <button class="button primary" type="submit">Importar extrato</button>
            <a class="button ghost" href="{{ route('banking.index') }}">Cancelar</a>
        </div>
    </form>
</section>

<section class="panel section-gap">
    <header class="panel-header"><div><h3>Como a duplicidade é tratada</h3><p>Regras já aplicadas pelo motor de ingestão (ADR-007 e ADR-008).</p></div></header>
    <ul class="bullet-list">
        <li>O mesmo arquivo importado duas vezes é reconhecido pelo hash e não duplica nada.</li>
        <li>Dentro do arquivo, cada lançamento é identificado pelo <code>FITID</code> do banco — reimportar um extrato que se sobrepõe a outro período aproveita o que já existe.</li>
        <li>Lançamentos recusados aparecem no lote com o motivo, sem interromper a importação dos demais.</li>
    </ul>
</section>
@endsection
