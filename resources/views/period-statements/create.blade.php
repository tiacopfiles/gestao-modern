@extends('layouts.app')
@section('title', 'Nova conciliação')
@section('section', 'Conciliação')
@section('page-title', 'Nova conciliação')

@php
    use App\Domain\Financial\Money;
    $brl = fn (?int $cents) => $cents === null ? '' : 'R$ '.number_format((float) Money::fromCents(abs($cents)), 2, ',', '.');
    $brlSinal = fn (int $cents) => ($cents < 0 ? '-' : '').'R$ '.number_format((float) Money::fromCents(abs($cents)), 2, ',', '.');
    $diaAnterior = \Illuminate\Support\Carbon::parse($from)->subDay();
@endphp

@section('content')

<div class="page-heading">
    <div>
        <span class="eyebrow">Conciliação</span>
        <h2>Nova conciliação</h2>
        <p>Abre com o que já está pago, recebido e lançado à mão na conta e no período. Depois, "Atualizar" traz o que for surgindo até fechar.</p>
    </div>
</div>

{{-- Sucesso e erro são renderizados pelo layout (layouts/app), não aqui: repetir mostrava o mesmo aviso duas vezes na tela. --}}

{{--
    Aviso deliberado: a dúvida "vai estragar alguma coisa?" é legítima e a tela
    tem que respondê-la sem a pessoa precisar perguntar.
--}}
<div class="alert alert-info" style="margin-bottom:14px">
    Abrir não altera nada. Nenhum título muda de status, nenhuma baixa é criada ou desfeita:
    o sistema apenas lê o que já existe e guarda este resumo, que pode ser atualizado depois.
</div>

<section class="panel" style="margin-bottom:14px">
    <form method="get" action="{{ route('period-statements.create') }}">
        <div class="filter-bar" style="padding:12px 16px;gap:12px;flex-wrap:wrap">
            <label>Conta
                <select name="account_id" onchange="this.form.submit()">
                    @foreach($contas as $c)
                        <option value="{{ $c->id }}" @selected($c->id === $accountId)>
                            {{ $c->nome }}@if($c->banco) — {{ $c->banco }}@endif
                        </option>
                    @endforeach
                </select>
            </label>
            {{--
                Conta e banco são coisas diferentes: "conta" aqui é a empresa
                herdada do sistema antigo; o extrato e o saldo são da conta
                bancária. É a mesma separação que o cabeçalho das planilhas faz
                em duas linhas.
            --}}
            <label>Banco
                @if($ehGrupoMesclado)
                    {{-- Empresa mesclada: as contas bancárias das duas empresas reais entram juntas
                         na conciliação, então não há um banco só para escolher aqui. --}}
                    <select disabled>
                        @foreach($bancos as $b)
                            <option>{{ $b->fullLabel() }}</option>
                        @endforeach
                    </select>
                @elseif($bancos->isEmpty())
                    <select disabled>
                        <option>— Nenhum banco cadastrado nesta conta —</option>
                    </select>
                @else
                    <select name="bank_account_id" onchange="this.form.submit()">
                        @foreach($bancos as $b)
                            <option value="{{ $b->id }}" @selected($b->id === $bankAccountId)>
                                {{ $b->fullLabel() }}@if($b->is_default) (padrão)@endif
                            </option>
                        @endforeach
                    </select>
                @endif
            </label>
            <label>De<input type="date" name="from" value="{{ $from }}"></label>
            <label>Até<input type="date" name="to" value="{{ $to }}"></label>
            <label>Saldo em {{ $diaAnterior->format('d/m/Y') }} <small style="color:#b3261e">*</small>
                <input type="text" name="opening" inputmode="decimal"
                       value="{{ $openingInformado ? number_format((float) Money::fromCents($openingCents), 2, ',', '.') : '' }}"
                       placeholder="Obrigatório — 0,00" required>
            </label>
            <button class="button primary" type="submit">Ver prévia</button>
        </div>
    </form>
    @if($openingInformado)
        <div style="padding:0 16px 12px;color:#666;font-size:13px">
            Saldo sugerido a partir da última conciliação fechada desta conta. Confira e ajuste se precisar.
        </div>
    @else
        <div style="padding:0 16px 12px;color:#b3261e;font-size:13px">
            Não há conciliação fechada anterior para sugerir um valor. Digite o saldo inicial da conta e clique em "Ver prévia".
        </div>
    @endif

    {{--
        A saída para quando o banco não está na lista. Antes disso, cadastrar
        uma conta bancária só era possível pelo comando `gestao:conta-bancaria`
        no servidor — e como a maioria das empresas não tem conta cadastrada, a
        tela travava sem dizer o que fazer. Fica aberto quando não há nenhum
        banco (é o caso em que a pessoa precisa dele) e recolhido quando já há.
    --}}
    @can('reconciliation:manage')
        <details style="border-top:1px solid #e6e8ec;padding:12px 16px" @if($bancos->isEmpty()) open @endif>
            <summary style="cursor:pointer;font-size:13px;color:#1a4fd6">
                {{ $bancos->isEmpty() ? 'Cadastrar o banco desta conta à mão' : 'Não achou o banco? Cadastrar outro à mão' }}
            </summary>
            <form method="post" action="{{ route('period-statements.bank-accounts.store') }}" style="margin-top:10px">
                @csrf
                <input type="hidden" name="account_id" value="{{ $accountId }}">
                <input type="hidden" name="from" value="{{ $from }}">
                <input type="hidden" name="to" value="{{ $to }}">
                <div class="filter-bar" style="padding:0;gap:12px;flex-wrap:wrap">
                    <label>Banco <small style="color:#b3261e">*</small>
                        <input type="text" name="bank_name" value="{{ old('bank_name') }}" placeholder="Banco Itaú" required>
                    </label>
                    <label>Código
                        <input type="text" name="bank_code" value="{{ old('bank_code') }}" placeholder="341" size="6">
                    </label>
                    <label>Agência <small style="color:#b3261e">*</small>
                        <input type="text" name="agency" value="{{ old('agency') }}" placeholder="6260" required>
                    </label>
                    <label>Conta <small style="color:#b3261e">*</small>
                        <input type="text" name="number" value="{{ old('number') }}" placeholder="13377-9" required>
                    </label>
                    <button class="button" type="submit">Cadastrar e usar</button>
                </div>
                <p style="margin:8px 0 0;color:#666;font-size:12px">
                    Cadastra a conta bancária em <strong>{{ $conta?->nome ?: 'na empresa selecionada' }}</strong> e já a seleciona aqui.
                    @if($bancos->isEmpty())
                        Sendo a primeira conta desta empresa, ela entra como <strong>padrão</strong> — é a que recebe as
                        baixas vindas das origens, que não trazem banco.
                    @else
                        A conta padrão da empresa <strong>não</strong> muda: as baixas vindas das origens continuam
                        na que já está marcada.
                    @endif
                </p>
            </form>
        </details>
    @endcan
</section>

<section class="panel" style="margin-bottom:14px">
    <div style="padding:14px 16px">
        {{--
            As duas linhas do cabeçalho da planilha, nesta ordem: a empresa e a
            conta bancária. O banco vem da conta escolhida no filtro; o campo
            `contas.banco` só entra como reserva para quem ainda não cadastrou
            conta bancária nenhuma.
        --}}
        <div style="font-size:15px"><strong>Conta:</strong> {{ $conta?->nome ?: '—' }}</div>
        <div style="font-size:15px">
            <strong>Banco:</strong>
            @if($ehGrupoMesclado)
                {{ $bancos->map->fullLabel()->implode(' + ') ?: '—' }}
            @else
                {{ $banco?->fullLabel() ?: ($conta?->banco ?: '—') }}
            @endif
        </div>
    </div>
</section>

@if($semConta > 0)
    <div class="alert" style="margin-bottom:14px;border-color:#ecd9aa;background:#fff9e8;color:#815d12">
        {{ $semConta }} movimento(s) realizado(s) neste período aguardando definição de conta — não pertencem a
        nenhuma conciliação até o cadastro na origem ser corrigido.
    </div>
@endif

@if($semBanco > 0)
    <div class="alert" style="margin-bottom:14px;border-color:#ecd9aa;background:#fff9e8;color:#815d12">
        {{ $semBanco }} movimento(s) realizado(s) neste período sem conta bancária definida — esta empresa tem
        mais de uma conta ativa, então não dá para deduzir por onde o dinheiro passou. Defina a conta de cada um
        para que entrem na conciliação certa.
    </div>
@endif

<section class="panel table-panel">
    <header class="panel-header">
        <div>
            <h3>Prévia</h3>
            <p>É exatamente isto que fica gravado ao confirmar — mesmo cálculo, sem surpresa.</p>
        </div>
    </header>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>DATA</th><th>N° DOC</th><th>ID</th><th>HISTÓRICO</th>
                    <th class="align-right">ENTRADA</th><th class="align-right">SAÍDA</th><th class="align-right">SALDO</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background:#f6f7f9;font-weight:600">
                    <td></td><td></td><td></td>
                    <td>Saldo referente ao dia {{ $diaAnterior->format('d/m/Y') }}</td>
                    <td></td><td></td>
                    <td class="align-right">{{ $openingInformado ? $brlSinal($preview['opening_cents']) : '—' }}</td>
                </tr>
                @forelse($preview['lines'] as $linha)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($linha['movement_date'])->format('d/m/Y') }}</td>
                        <td>{{ $linha['document_number'] }}</td>
                        <td>{{ $linha['origin_id'] }}</td>
                        <td>{{ $linha['history'] }}</td>
                        <td class="align-right">{{ $brl($linha['amount_in_cents']) }}</td>
                        <td class="align-right">{{ $brl($linha['amount_out_cents']) }}</td>
                        <td class="align-right">{{ $openingInformado ? $brlSinal($linha['running_balance_cents']) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><span>⌕</span><strong>Nenhum movimento no período</strong><p>Não houve pagamento, recebimento nem lançamento manual nesta conta nessas datas.</p></div></td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr style="font-weight:600">
                    <td colspan="4">Totais do período</td>
                    <td class="align-right">{{ $brl($preview['total_in_cents']) }}</td>
                    <td class="align-right">{{ $brl($preview['total_out_cents']) }}</td>
                    <td class="align-right">{{ $openingInformado ? $brlSinal($preview['closing_cents']) : '—' }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>

@include('period-statements.partials.pending', ['pendentes' => $preview['pending'], 'brl' => $brl])

@can('reconciliation:manage')
    @if($openingInformado)
        <form method="post" action="{{ route('period-statements.store') }}" style="margin-top:14px">
            @csrf
            <input type="hidden" name="account_id" value="{{ $accountId }}">
            <input type="hidden" name="bank_account_id" value="{{ $bankAccountId }}">
            <input type="hidden" name="from" value="{{ $from }}">
            <input type="hidden" name="to" value="{{ $to }}">
            <input type="hidden" name="opening" value="{{ number_format((float) Money::fromCents($openingCents), 2, ',', '.') }}">
            <button class="button primary" type="submit">Abrir conciliação com este saldo</button>
        </form>
    @else
        <p style="margin-top:14px;color:#666;font-size:13px">Digite o saldo inicial acima e clique em "Ver prévia" para poder confirmar.</p>
    @endif
@endcan

@endsection
