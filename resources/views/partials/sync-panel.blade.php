{{--
    Sincronização com Contas a Pagar e Contas a Receber.

    A origem é somente leitura: este botão nunca escreve nos sistemas antigos.
    Falha aparece com o mesmo destaque que sucesso — esconder erro de
    sincronização faria o operador confiar em número desatualizado.
--}}

@if(session('sync_erro'))
    <div class="alert alert-danger">
        <strong>Não foi possível concluir a sincronização.</strong>
        <p>{{ session('sync_erro') }}</p>
    </div>
@endif

@if(session('sync_resultado'))
    <div class="alert alert-success">
        <strong>Sincronização concluída.</strong>
        <div class="sync-result">
            @foreach(session('sync_resultado') as $r)
                <div class="sync-result-item">
                    <b>{{ $r['origem'] }}</b>
                    <span>{{ $r['novos'] }} novo(s)</span>
                    <span>{{ $r['atualizados'] }} atualizado(s)</span>
                    <span>{{ $r['baixas'] }} baixa(s)</span>
                    @if($r['erros'] > 0)<span class="danger-text">{{ $r['erros'] }} não aplicado(s)</span>@endif
                </div>
            @endforeach
        </div>
    </div>
@endif

<section class="sync-panel">
    <div class="sync-status">
        @foreach(['LEGACY_PAYABLE' => 'Contas a pagar', 'LEGACY_RECEIVABLE' => 'Contas a receber'] as $code => $rotulo)
            @php $c = $syncCycles[$code] ?? null; @endphp
            <div class="sync-origin">
                {{--
                    Três estados, não dois: conflito de regra não é falha do
                    sistema e não pode acender a mesma luz vermelha de um banco
                    fora do ar — senão a operação aprende a ignorar as duas.
                --}}
                <span class="sync-dot {{ $c === null ? 'dot-idle' : ($c->isFailure() ? 'dot-error' : ($c->hasConflicts() ? 'dot-warn' : 'dot-ok')) }}"></span>
                <div>
                    <b>{{ $rotulo }}</b>
                    @if($c === null)
                        <small>Nunca sincronizado</small>
                    @else
                        <small>
                            {{ $c->started_at->format('d/m/Y \à\s H:i') }}
                            @if($c->isFailure()) · <span class="danger-text">{{ $c->error_count }} não aplicado(s)</span>@endif
                            @if($c->hasConflicts())
                                · <a href="{{ route('sync.conflicts') }}">{{ $c->conflict_count }} em quarentena</a>
                            @endif
                        </small>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @can('reconciliation:manage')
        <form method="post" action="{{ route('sync.store') }}" data-sync-form>
            @csrf
            <button type="submit" class="button primary" data-sync-button>
                <span data-sync-label>Sincronizar agora</span>
            </button>
        </form>
    @endcan
</section>

<script>
    // Sincronizar leva dezenas de segundos. Sem travar o botao, o operador clica
    // de novo achando que nao funcionou e dispara um segundo ciclo.
    document.querySelectorAll('[data-sync-form]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var botao = form.querySelector('[data-sync-button]');
            var texto = form.querySelector('[data-sync-label]');
            if (botao) { botao.disabled = true; botao.classList.add('is-loading'); }
            if (texto) { texto.textContent = 'Sincronizando…'; }
        });
    });
</script>
