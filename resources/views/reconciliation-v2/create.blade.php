@extends('layouts.app')
@section('title', 'Nova sessão de conciliação')
@section('section', 'Conciliação v2')
@section('page-title', 'Nova sessão de conciliação')

@section('content')
<a class="back-link" href="{{ route('reconciliation-v2.index') }}">← Voltar</a>
<form class="panel form-panel" method="post" action="{{ route('reconciliation-v2.store') }}">
    @csrf
    <header class="panel-header"><div><h3>Conta e período</h3><p>A sessão é única para esta combinação. Não representa fechamento contábil.</p></div></header>
    <div class="form-grid">
        <label class="field"><span>Conta *</span><select name="account_id" required><option value="">Selecione</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string)old('account_id')===(string)$account->id)>{{ $account->nome }} · {{ $account->nome_detalhado }}</option>@endforeach</select></label>
        <label class="field"><span>Data inicial *</span><input type="date" name="period_start" required value="{{ old('period_start',now()->startOfMonth()->toDateString()) }}"></label>
        <label class="field"><span>Data final *</span><input type="date" name="period_end" required value="{{ old('period_end',now()->endOfMonth()->toDateString()) }}"></label>
    </div>
    <footer class="form-actions"><button class="button primary" type="submit">Criar e abrir sessão</button></footer>
</form>
@endsection
