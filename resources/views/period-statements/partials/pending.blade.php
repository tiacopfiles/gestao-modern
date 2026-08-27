{{--
    O rodapé da conciliação: títulos que ainda não caíram no banco.

    Vem depois do saldo final, separado, e sem coluna de saldo — do mesmo jeito
    que fecha a aba do mês nas planilhas. Não é movimento: se somasse no saldo,
    o relatório passaria a misturar o que aconteceu com o que ainda vai
    acontecer, que é o erro que a conciliação existe para evitar.

    Espera:
      $pendentes — lista de arrays (prévia) ou de PeriodStatementLine (gravado)
      $brl       — formatador de centavos
--}}
@php
    // A prévia entrega array e a tela do relatório gravado entrega model. Ler
    // os dois com a mesma sintaxe evita duplicar este arquivo.
    $campo = fn ($linha, string $nome) => is_array($linha) ? ($linha[$nome] ?? null) : $linha->{$nome};
@endphp

<section class="panel table-panel" style="margin-top:14px">
    <header class="panel-header">
        <div>
            <h3>Em aberto no fim do período</h3>
            <p>
                Títulos desta conta que ainda não caíram no banco. Não entram no saldo nem nos
                totais acima — estão aqui porque já são pendência conhecida.
            </p>
        </div>
        @if(count($pendentes) > 0)
            <span class="badge warning">{{ count($pendentes) }} título(s)</span>
        @endif
    </header>

    @if(count($pendentes) === 0)
        <div class="empty-state small">
            <span>✓</span>
            <strong>Nada em aberto</strong>
            <p>Todos os títulos desta conta emitidos até o fim do período já foram baixados.</p>
        </div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>N° DOC</th><th>ID</th><th>HISTÓRICO</th><th>VENCIMENTO</th>
                        <th class="align-right">A RECEBER</th><th class="align-right">A PAGAR</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pendentes as $linha)
                        @php
                            $vencimento = $campo($linha, 'due_date');
                            $entrada = $campo($linha, 'amount_in_cents');
                            $saida = $campo($linha, 'amount_out_cents');
                            $vencido = $vencimento !== null
                                && \Illuminate\Support\Carbon::parse($vencimento)->isPast();
                        @endphp
                        <tr>
                            <td>{{ $campo($linha, 'document_number') ?: '—' }}</td>
                            <td>{{ $campo($linha, 'origin_id') ?: '—' }}</td>
                            <td>{{ $campo($linha, 'history') }}</td>
                            <td>
                                {{ $vencimento === null ? '—' : \Illuminate\Support\Carbon::parse($vencimento)->format('d/m/Y') }}
                                @if($vencido)<small class="danger-text">Vencido</small>@endif
                            </td>
                            <td class="align-right">{{ $brl($entrada) }}</td>
                            <td class="align-right">{{ $brl($saida) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="font-weight:600">
                        <td colspan="4">Total em aberto</td>
                        <td class="align-right">
                            {{ $brl(collect($pendentes)->sum(fn ($l) => (int) $campo($l, 'amount_in_cents'))) }}
                        </td>
                        <td class="align-right">
                            {{ $brl(collect($pendentes)->sum(fn ($l) => (int) $campo($l, 'amount_out_cents'))) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</section>
