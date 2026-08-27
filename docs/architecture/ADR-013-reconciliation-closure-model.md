# ADR-013 — Modelo de fechamento de conciliação

- Status: aceito
- Data: 2026-08-14

## Contexto

As Fases 1–5 registram fatos financeiros, fatos bancários e decisões de conciliação persistentes (`reconciliation_matches`), mas nenhuma unidade representa "este período está fechado e o resultado consolidado é este, permanentemente". No legado, um período fechado pode mudar de resultado silenciosamente se dados mudarem depois — inaceitável para o núcleo novo.

## Decisão

`reconciliation_sessions` continua sendo a unidade "conta + período" (`unique(account_id, period_start, period_end)`). A Fase 6 não cria uma unidade de agregação concorrente: um fechamento é um evento que acontece *sobre* uma sessão existente, registrado em `reconciliation_closures`. Uma sessão pode ter múltiplos fechamentos ao longo do tempo (fechar → reabrir → fechar de novo), formando uma cadeia auditável via `sequence_number` e `previous_closure_id`.

Modelo de dados híbrido (avaliadas três opções — snapshot completo, apenas referências, híbrido — ver `docs/phase-6-design/FASE_6_ARQUITETURA.md` §3 para a análise completa):

- `reconciliation_closure_matches`/`reconciliation_closure_exceptions` guardam a referência (FK) mais uma cópia mínima e imutável dos campos que definem o resultado financeiro daquela linha no momento do fechamento (`captured_status`, `captured_total_amount`, `captured_type`) — mesmo padrão já usado em `reconciliation_exceptions.evidence`/`reconciliation_candidates.evidence`.
- `snapshot_payload` (`LONGTEXT`, cast `array`) consolida a lista canonizada e ordenada de todas as linhas incluídas, a versão do motor de matching vigente, e as métricas agregadas — o material sobre o qual `closure_hash` é calculado.
- Métricas vão para `reconciliation_closure_metrics`, um modelo chave/valor, porque nem todas as métricas do negócio estão definidas ainda (ver ADR-014 sobre política) — evita nova migration a cada métrica nova.

Estendido o enum `ReconciliationSessionStatus` com `Closed`/`Reopened` (a coluna `status` já era `string(20)`, nenhuma migration de schema foi necessária para isso). `READY_TO_CLOSE` **não é persistido** — é computado sob demanda por `ReconciliationClosureValidator`, evitando um segundo lugar de verdade que poderia divergir do resultado real do validador.

## Imutabilidade

Uma vez criada, uma linha de `reconciliation_closures` nunca recebe `UPDATE` nos campos de conteúdo (`snapshot_payload`, `closure_hash`, `period_start`, `period_end`, `closed_by`, `closed_at`). As únicas escritas subsequentes são as do fluxo de reabertura: `status`, `reopened_by`, `reopened_at`. Uma reabertura seguida de novo fechamento cria uma **nova linha** (`previous_closure_id` aponta para a anterior) — o mesmo padrão que `void` já aplica em `reconciliation_matches` (ADR-009) e que `JUSTIFIED`/`RESOLVED` já aplicam em `reconciliation_exceptions` (ADR-012).

## Sobreposição de período

Duas sessões da mesma conta podem coexistir com períodos sobrepostos enquanto nada é fechado, mas dois fechamentos `CLOSED` simultâneos com sobreposição quebrariam a pergunta "qual fechamento é autoridade para esta data". `ReconciliationClosureService::close()` verifica, dentro da mesma transação que trava a sessão, se existe outro fechamento `CLOSED` da mesma conta com período sobreposto (`lockForUpdate` nas linhas candidatas). MariaDB 10.1 não oferece constraint declarativa de range; a proteção é inteiramente transacional em aplicação, mesmo racional já usado para disponibilidade de alocação (ADR-009).

## Bloqueio de mutações pós-fechamento

`ManualReconciliationService::confirm/void`, `ReconciliationCandidateService::accept/reject`, `ReconciliationExceptionService::justify` e `ReconciliationMatchingEngine::generate` passaram a chamar `assertSessionOpenForWrite()` (trait `AssertsReconciliationSessionOpenForWrite`) logo após localizar a sessão, antes de qualquer outro lock. Essa é a única camada que não pode ser pulada — a UI apenas oculta ações quando a sessão está fechada, mas não é a proteção real.

## Integridade e compatibilidade

- Migrations aditivas, `InnoDB`, dinheiro em `DECIMAL(15,2)`, hash em `CHAR(64)`, sem coluna `JSON` nativa (MariaDB 10.1).
- FKs novas usam `RESTRICT`; nenhuma `CASCADE`/`SET NULL`.
- `account_id` continua sem FK contra estrutura legada, mesmo padrão de `reconciliation_sessions`.
- A concorrência real (locks InnoDB) segue como pendência de homologação MariaDB — ver ADR-009 e `docs/operations/PHASE-6-CLOSING-RUNBOOK.md`.

## Consequências

- Um fechamento histórico é reproduzível: o hash detecta alteração indevida de conteúdo (ADR-014).
- Nenhum efeito financeiro é criado por fechar/reabrir — nenhum `title_settlement`, nenhuma alteração em `financial_titles`/`bank_transactions`.
- `/conciliacoes` (legado) e Contas a Pagar/Receber permanecem intocados.
- Auto-match, baixa automática, saldo bancário de autoridade e exportação de relatório continuam fora do escopo desta fase.
