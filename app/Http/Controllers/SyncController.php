<?php

namespace App\Http\Controllers;

use App\Application\Integration\OriginSyncService;
use App\Contracts\AuditEventRecorder;
use App\Models\OriginSyncConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Botão "Sincronizar agora".
 *
 * Lê as duas origens em modo somente leitura e atualiza apenas o banco do
 * Gestão. O resultado de cada ciclo volta na sessão para ser exibido; falha
 * nunca é escondida.
 */
class SyncController extends Controller
{
    /**
     * Janela padrão do botão, em dias para trás e para frente a partir de hoje.
     *
     * Um ciclo do ano inteiro percorre ~13 mil títulos e leva vários minutos —
     * tempo demais para uma requisição de navegador, que morreria no
     * max_execution_time e deixaria o operador sem resposta. A janela curta
     * cobre onde a movimentação real acontece (vencimentos próximos); o ano
     * completo continua disponível pelo comando `gestao:sync` e pela tarefa
     * agendada, e também aqui passando `from`/`to` explicitamente.
     */
    private const DEFAULT_WINDOW_DAYS = 90;

    public function store(Request $request, OriginSyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = (string) ($validated['from'] ?? now()->subDays(self::DEFAULT_WINDOW_DAYS)->toDateString());
        $to = (string) ($validated['to'] ?? now()->addDays(self::DEFAULT_WINDOW_DAYS)->toDateString());

        $lock = Cache::lock('gestao:sync', 3600);

        if (! $lock->get()) {
            return back()->with('sync_erro', 'Já existe uma sincronização em andamento. Aguarde e tente de novo.');
        }

        // Um ciclo leva dezenas de segundos mesmo quando nada mudou. Sem isto o
        // max_execution_time do PHP mataria a requisição no meio, deixando o
        // ciclo marcado como RUNNING para sempre e o operador sem resposta.
        set_time_limit(0);

        try {
            $cycles = $sync->sync($from, $to, 'manual', $request->user()?->id);

            $resumo = [];

            foreach ($cycles as $cycle) {
                $resumo[] = [
                    'origem' => $cycle->label(),
                    'status' => $cycle->status,
                    'lidos' => $cycle->source_rows_read,
                    'novos' => $cycle->created_count,
                    'atualizados' => $cycle->updated_count,
                    'sem_mudanca' => $cycle->unchanged_count,
                    'baixas' => $cycle->settlements_created,
                    'rejeitados' => $cycle->source_rows_rejected,
                    'motivos_rejeicao' => $cycle->rejectionReasons(),
                    'conflitos' => $cycle->conflict_count,
                    'erros' => $cycle->error_count,
                    'segundos' => $cycle->durationSeconds(),
                    'detalhe_erro' => $cycle->error_summary,
                ];
            }

            return back()->with('sync_resultado', $resumo);
        } catch (Throwable $e) {
            return back()->with('sync_erro', 'Falha na sincronização: '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * A fila de quarentena: títulos que a origem tenta alterar contra a regra.
     *
     * Abertos primeiro, e dentro deles os mais insistentes no topo — um
     * conflito que reapareceu 300 vezes é a origem afirmando todo dia que o
     * Gestão está errado, e merece decisão antes de um que apareceu uma vez.
     */
    public function conflicts(Request $request): View
    {
        $abertos = OriginSyncConflict::query()
            ->with('title')
            ->whereNull('resolved_at')
            ->orderByDesc('occurrences')
            ->orderByDesc('last_seen_at')
            ->paginate(25);

        $resolvidos = OriginSyncConflict::query()
            ->whereNotNull('resolved_at')
            ->count();

        return view('sync.conflicts', compact('abertos', 'resolvidos'));
    }

    /**
     * Marca o conflito como resolvido — decisão humana, com motivo.
     *
     * Não altera título nem origem: quem resolve de verdade é a correção na
     * origem ou a aceitação da divergência. Isto só tira da fila o que já foi
     * decidido. Se a origem insistir de novo, o próximo ciclo reabre o
     * conflito automaticamente, e é esse o comportamento correto — resolver não
     * pode virar uma forma de calar o aviso.
     */
    public function resolveConflict(
        Request $request,
        OriginSyncConflict $conflict,
        AuditEventRecorder $audit,
    ): RedirectResponse {
        $dados = $request->validate(
            ['note' => ['required', 'string', 'max:250']],
            ['note.required' => 'Explique o que foi decidido sobre este conflito.'],
            ['note' => 'motivo'],
        );

        $antes = [
            'resolved_at' => null,
            'occurrences' => $conflict->occurrences,
            'reason' => $conflict->reason,
        ];

        $conflict->update([
            'resolved_at' => now(),
            'resolved_by' => $request->user()?->id,
            'resolution_note' => $dados['note'],
        ]);

        $audit->record(
            'ORIGIN_SYNC_CONFLICT_RESOLVED',
            OriginSyncConflict::class,
            $conflict->id,
            $antes,
            [
                'source_code' => $conflict->source_code,
                'external_id' => $conflict->external_id,
                'resolution_note' => $dados['note'],
            ],
            null,
            $request->user()?->id,
            (string) Str::uuid(),
        );

        return back()->with('success', 'Conflito marcado como resolvido. Se a origem insistir, ele volta para a fila.');
    }
}
