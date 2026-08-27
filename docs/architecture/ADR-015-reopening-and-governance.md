# ADR-015 — Reabertura, RBAC e política de divergências do fechamento

- Status: aceito, com pendências de negócio explicitamente documentadas (ver seção final)
- Data: 2026-08-14

## Contexto

`FASE_6_PERGUNTAS_NEGOCIO.md` lista 14 perguntas de negócio ainda não respondidas pelo financeiro (política de divergência aberta, segregação ator-fecha≠ator-reabre, saldo de autoridade, four-eyes, prazo de fechamento, etc.). O desenvolvimento da Fase 6 foi autorizado a prosseguir sem aguardar essas respostas, usando exclusivamente os defaults seguros já documentados no design — nunca uma política definitiva inventada.

## Decisão — RBAC

Estende exatamente o padrão real do projeto (`Gate::define` por allowlist de IDs vinda de `config()`/`env()`, sem tabela de papéis — ver `app/Providers/AppServiceProvider.php`):

| Gate | Config | Env |
|---|---|---|
| `reconciliation:close` | `reconciliation.close_user_ids` | `RECONCILIATION_CLOSE_USER_IDS` |
| `reconciliation:reopen` | `reconciliation.reopen_user_ids` | `RECONCILIATION_REOPEN_USER_IDS` |
| `reconciliation:export` | `reconciliation.export_user_ids` (+ quem tem `close`) | `RECONCILIATION_EXPORT_USER_IDS` |
| `reconciliation:admin` | `reconciliation.admin_user_ids` | `RECONCILIATION_ADMIN_USER_IDS` |

`reconciliation:view`/`reconciliation:manage` não mudam de significado. Nenhuma tabela de papéis foi criada — seria desproporcional ao restante da base.

## Decisão — Política de divergências abertas: Governada

Das três alternativas avaliadas em `FASE_6_STATE_MACHINE.md` §4 (Rígida, Governada, Extraordinária), esta implementação usa **Governada** como único comportamento ativo: `ReconciliationClosureValidator` bloqueia o fechamento (`CLOSURE_OPEN_EXCEPTIONS`) quando existem `reconciliation_exceptions` com `status` `OPEN`/`IN_REVIEW`; exceções `JUSTIFIED`/`RESOLVED` não bloqueiam. Reaproveita 100% do fluxo humano já existente (`ReconciliationExceptionService::justify`, ADR-012), sem inventar um novo tipo de aprovação.

A política **Extraordinária** (fechar com exceção `OPEN` mediante permissão elevada extra) **não foi implementada nesta fase** — nenhum código de bypass existe, e a flag `RECONCILIATION_CLOSURE_FORCE_ENABLED` mencionada no design não foi criada, para não deixar uma flag "morta" no sistema. Se o negócio aprovar essa política, ela deve ser implementada com ADR próprio.

## Decisão — Reabertura

`ReconciliationReopeningService::reopen()` é uma operação excepcional: exige `reason` não vazio (1–1000 caracteres, mesma validação de `void_reason`, mas aqui nunca nullable), permissão `reconciliation:reopen`, e registra ator/timestamp/`correlation_id`/`previous_status` em `reconciliation_reopenings`. Nunca apaga `reconciliation_closures` — apenas atualiza `status`/`reopened_by`/`reopened_at` na linha existente.

**Segregação ator-fecha≠ator-reabre**: **não implementada nesta fase.** O mesmo usuário que executou `close()` pode executar `reopen()` sobre o mesmo fechamento — nenhuma checagem `closed_by !== $actorId` existe em `ReconciliationReopeningService`. Esta é uma decisão de negócio explicitamente pendente (`FASE_6_PERGUNTAS_NEGOCIO.md` pergunta 11); implementá-la a priori seria inventar política financeira sem autorização.

## Pendências de negócio (não resolvidas por este ADR)

As 14 perguntas de `FASE_6_PERGUNTAS_NEGOCIO.md` continuam abertas. Defaults seguros efetivamente em produção nesta implementação:

- Divergência: política Governada (acima) — decidido por este ADR como default operacional, não como resposta definitiva do negócio.
- Candidato `PENDING` não decidido: nunca bloqueia fechamento (`CLOSURE_PENDING_CANDIDATES` é aviso, não blocker).
- Sessão sem nenhum match confirmado: pode ser fechada (`CLOSURE_EMPTY_SESSION` é aviso, não blocker).
- Saldo de autoridade / tolerância de saldo não conciliado: não implementado — domínio moderno não representa saldo bancário inicial/final; a métrica correspondente fica ausente, nunca inventada.
- Four-eyes (segunda aprovação para fechar): não implementado.
- Prazo/janela de reabertura: sem limite temporal nesta fase.
- Mesmo ator fecha e reabre: permitido (ver acima).
- Escopo de permissão por conta/empresa: allowlist global, mesma limitação já existente em `reconciliation:manage` hoje (IDOR conhecido e documentado, não piorado nem resolvido por esta fase).
- Formato de exportação de relatório: fora do escopo — `reconciliation:export` e o evento `RECONCILIATION_CLOSURE_EXPORT_GENERATED` estão especificados no design, mas nenhum endpoint de exportação foi implementado.

Cada resposta de negócio futura deve gerar um ADR específico (`docs/architecture/ADR-01X-reconciliation-closure-policy-<tema>.md`) e atualização deste documento — nunca uma mudança de comportamento sem registro.
