@extends('layouts.app')
@php($payable=$kind==='payable')
@section('title',$record->exists?'Editar título':'Novo título')
@section('section','Financeiro')
@section('page-title',$record->exists?'Editar título':($payable?'Nova conta a pagar':'Nova conta a receber'))
@section('content')
<a class="back-link" href="{{ route($payable?'payables.index':'receivables.index') }}">← Voltar</a>
<form class="panel form-panel" method="post" action="{{ $record->exists ? route($payable?'payables.update':'receivables.update',$record) : route($payable?'payables.store':'receivables.store') }}">@csrf @if($record->exists)@method('put')@endif
<header class="panel-header"><div><h3>Dados do título</h3><p>Campos marcados são validados antes da gravação.</p></div></header>
<div class="form-grid">
<label class="field"><span>{{ $payable?'Fornecedor':'Cliente' }} *</span><select name="{{ $payable?'fornecedor':'cliente' }}" required><option value="">Selecione</option>@foreach($choices['parties'] as $p)<option value="{{ $p->id }}" @selected((string)old($payable?'fornecedor':'cliente',$record->{$payable?'fornecedor':'cliente'})===(string)$p->id)>{{ $p->nome_fantasia?:$p->razao_social }}</option>@endforeach</select></label>
<label class="field"><span>Documento</span><input name="numero_doc" value="{{ old('numero_doc',$record->numero_doc) }}"></label>
<label class="field"><span>Tipo *</span><select name="tipo" required><option value="">Selecione</option>@foreach($choices['types'] as $o)<option value="{{ $o->id }}" @selected((string)old('tipo',$record->tipo)===(string)$o->id)>{{ $o->nome }}</option>@endforeach</select></label>
<label class="field"><span>Categoria *</span><select name="categoria" required><option value="">Selecione</option>@foreach($choices['categories'] as $o)<option value="{{ $o->id }}" @selected((string)old('categoria',$record->categoria)===(string)$o->id)>{{ $o->nome }}</option>@endforeach</select></label>
<label class="field"><span>Conta *</span><select name="conta" required><option value="">Selecione</option>@foreach($choices['accounts'] as $o)<option value="{{ $o->id }}" @selected((string)old('conta',$record->conta)===(string)$o->id)>{{ $o->nome }} · {{ $o->nome_detalhado }}</option>@endforeach</select></label>
<label class="field"><span>Centro de custo *</span><select name="centrocusto" required><option value="">Selecione</option>@foreach($choices['costCenters'] as $o)<option value="{{ $o->id }}" @selected((string)old('centrocusto',$record->centrocusto)===(string)$o->id)>{{ $o->nome }}</option>@endforeach</select></label>
<label class="field"><span>Situação *</span><select name="situacao" required><option value="">Selecione</option>@foreach($choices['statuses'] as $o)<option value="{{ $o->id }}" @selected((string)old('situacao',$record->situacao)===(string)$o->id)>{{ $o->nome }}</option>@endforeach</select></label>
<label class="field"><span>Emissão *</span><input type="date" name="data_emissao" required value="{{ old('data_emissao',$record->getRawOriginal('data_emissao')) }}"></label>
<label class="field"><span>Vencimento *</span><input type="date" name="data_vencimento" required value="{{ old('data_vencimento',$record->getRawOriginal('data_vencimento')) }}"></label>
<label class="field"><span>Pagamento/recebimento</span><input type="date" name="data_pagamento" value="{{ old('data_pagamento',str_starts_with((string)$record->getRawOriginal('data_pagamento'),'0000-')?'':$record->getRawOriginal('data_pagamento')) }}"></label>
<label class="field"><span>Competência</span><input name="competencia" value="{{ old('competencia',$record->competencia) }}"></label>
<label class="field"><span>Parcela</span><input name="pc" value="{{ old('pc',$record->pc) }}"></label>
<label class="field"><span>Total de parcelas</span><input name="numero_pc" value="{{ old('numero_pc',$record->numero_pc) }}"></label>
<label class="field"><span>Valor *</span><input type="number" step="0.01" min="0" name="valor" required value="{{ old('valor',$record->valor) }}"></label>
<label class="field"><span>Acréscimo</span><input type="number" step="0.01" min="0" name="acrescimo" value="{{ old('acrescimo',$record->acrescimo??0) }}"></label>
<label class="field"><span>Desconto</span><input type="number" step="0.01" min="0" name="desconto" value="{{ old('desconto',$record->desconto??0) }}"></label>
<label class="field span-all"><span>Observações</span><textarea name="obs" rows="3">{{ old('obs',$record->obs) }}</textarea></label>
</div><footer class="form-actions"><a class="button ghost" href="{{ route($payable?'payables.index':'receivables.index') }}">Cancelar</a><button class="button primary" type="submit">Salvar título</button></footer></form>
@endsection
