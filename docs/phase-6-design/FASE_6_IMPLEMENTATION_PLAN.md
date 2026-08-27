# Fase 6 — Plano de implementação

- Status: **proposta, não implementada**
- Pré-condição: `PACOTE_CONTINUACAO_FASE_5_5C_GO.md` (ou pacote equivalente) declarando `GO PARA FASE 6`
- Objetivo deste documento: dar ao Codex uma ordem de execução objetiva, com critério de aceite por etapa, para que a implementação comece imediatamente após o GO, sem retrabalho de decisão.

## Feature flags — matriz completa

| Flag | Default | Depende de | Efeito quando `false` |
|---|---|---|---|
| `RECONCILIATION_V2_ENABLED` | `false` | — | `/reconciliacao-v2` inteiro 404 (já implementado) |
| `RECONCILIATION_MATCHING_ENABLED` | `false` | `RECONCILIATION_V2_ENABLED=true` | sugestões/divergências 404, match manual continua (já implementado) |
| `RECONCILIATION_CLOSING_ENABLED` | `false` | `RECONCILIATION_V2_ENABLED=true` | rotas de fechamento/reabertura 404; **não depende de matching** — closing funciona com matching desligado |
| `RECONCILIATION_CLOSURE_FORCE_ENABLED` | `false` | `RECONCILIATION_CLOSING_ENABLED=true` | política Extraordinária (fechar com exceção `OPEN`) indisponível mesmo para `reconciliation:admin` — só existe se explicitamente decidida no negócio (pergunta 3) |

### Kill switch — o que continua funcionando com `RECONCILIATION_CLOSING_ENABLED=false`

```text
conciliação manual (Fase 4)      → continua
matching assistido (Fase 5)      → continua, se sua própria flag estiver ativa
API V1                            → continua
importação bancária/OFX           → continua
conciliação legada (/conciliacoes) → continua
```

Nenhuma dependência inversa: desligar closing nunca desliga V2 ou matching.

---

## 6.1 — Migrations

**Escopo:** as 5 migrations de `FASE_6_MODELO_DADOS.md` §2–6, extensão do enum `ReconciliationSessionStatus`.

**Critério de aceite:**
- `php artisan migrate` cria as 5 tabelas com os tipos/constraints exatos especificados;
- `php artisan migrate:rollback` remove as 5 tabelas sem erro (todas com `down()` completo);
- suíte de schema MariaDB (`FASE_6_TEST_PLAN.md` §18) verde;
- nenhuma migration existente foi alterada.

## 6.2 — Domain

**Escopo:** `ReconciliationClosureStatus` enum, `ReconciliationClosureReadiness` value object, exceção de domínio (reaproveitar `ReconciliationRuleViolation` com novos códigos `CLOSURE_*`, não criar uma segunda hierarquia de exceção).

**Critério de aceite:**
- enums cobrem exatamente `CLOSED`/`REOPENED` — nenhum valor extra;
- `ReconciliationClosureReadiness` é imutável (`readonly`), com `ready: bool`, `blockers: list<array{code:string,message:string}>`, `warnings: list<array{code,message}>`.

## 6.3 — Closure service (validador + builder + hash)

**Escopo:** `ReconciliationClosureValidator`, `ReconciliationClosureSnapshotBuilder`, `ReconciliationClosureHashService` — a lógica pura, testável sem depender ainda da orquestração transacional.

**Pré-requisito real:** respostas de `FASE_6_PERGUNTAS_NEGOCIO.md` (ou uso explícito dos defaults seguros documentados, registrado em commit/PR).

**Critério de aceite:**
- `ReconciliationClosureHashService` tem testes unitários puros (sem banco) cobrindo determinismo, sensibilidade a mudança de conteúdo e insensibilidade a ordem de inserção (`FASE_6_TEST_PLAN.md` §6);
- `ReconciliationClosureValidator` implementa todos os `blockers` técnicos de `FASE_6_STATE_MACHINE.md` §3 e os `warnings` de negócio com o default (Governada) ativo;
- nenhuma regra de negócio não respondida vira `blocker` fixo.

## 6.4 — Locking e orquestração (`ReconciliationClosureService`)

**Escopo:** `ReconciliationClosureService::close()` — orquestra validador + snapshot + hash dentro de `DB::transaction(..., 3)`, com `lockForUpdate` na ordem de `FASE_6_STATE_MACHINE.md` §9.

**Critério de aceite:**
- testes de `FASE_6_TEST_PLAN.md` §1–7 verdes em SQLite;
- adiciona a checagem `assertSessionOpenForWrite` (ou equivalente) em `ManualReconciliationService::confirm/void`, `ReconciliationCandidateService::accept/reject`, `ReconciliationExceptionService::justify`, `ReconciliationMatchingEngine::generate` — **esta etapa modifica código existente das Fases 4/5**, é a única do plano que faz isso, e deve vir com regressão completa da suíte atual (93 testes + os novos) antes de prosseguir.

## 6.5 — Reopening

**Escopo:** `ReconciliationReopeningService::reopen()`.

**Critério de aceite:**
- testes de `FASE_6_TEST_PLAN.md` §8–9 verdes;
- reabertura nunca sobrescreve `snapshot_payload`/`closure_hash` da linha original — apenas `status`/`reopened_by`/`reopened_at`.

## 6.6 — Audit

**Escopo:** eventos e payloads mínimos, seguindo o mesmo formato de `AuditEventRecorder::record()` (`before`/`after` como arrays associativos, nunca objetos completos do domínio nem dados livres de texto):

| Evento | Quando | `before` | `after` |
|---|---|---|---|
| `RECONCILIATION_CLOSURE_CREATED` | início da transação de `close()`, antes de validar | `null` | `{session_id, account_id, period_start, period_end}` |
| `RECONCILIATION_CLOSURE_COMPLETED` | fechamento persistido com sucesso | `null` | `{closure_id, sequence_number, closure_hash, engine_version, matches_count, exceptions_count, closed_by, closed_at}` |
| `RECONCILIATION_CLOSURE_REOPENED` | reabertura persistida | `{closure_id, status: 'CLOSED'}` | `{closure_id, status: 'REOPENED', reopened_by, reopened_at, reason}` |
| `RECONCILIATION_CLOSURE_RECLOSED` | novo fechamento após reabertura (mesma sessão) | `{previous_closure_id, previous_sequence_number}` | `{closure_id, sequence_number, closure_hash}` |
| `RECONCILIATION_CLOSURE_EXPORT_GENERATED` | exportação/relatório gerado | `null` | `{closure_id, format, exported_by, exported_at}` — sem conteúdo do relatório em si |

Nunca incluir `snapshot_payload` completo em `before`/`after` de `audit_events` — o payload já está em `reconciliation_closures.snapshot_payload`, duplicá-lo em auditoria só infla `longText` sem adicionar rastreabilidade nova.

**Critério de aceite:** cada transição do state machine gera exatamente um evento coerente, com `correlation_id` propagado; testes de `FASE_6_TEST_PLAN.md` §16.

## 6.7 — RBAC

**Escopo:** gates `reconciliation:close`, `reconciliation:reopen`, `reconciliation:export`, `reconciliation:admin` em `AppServiceProvider`; chaves novas em `config/reconciliation.php`.

**Critério de aceite:** testes de `FASE_6_TEST_PLAN.md` §10–11 verdes; nenhuma alteração nos gates `reconciliation:view`/`manage` existentes.

## 6.8 — Feature flag

**Escopo:** `ReconciliationClosingFeature`, `EnsureReconciliationClosingEnabled` middleware, alias de rota `reconciliation.closing`, chave `closing_enabled` em `config/reconciliation.php`.

**Critério de aceite:** testes de `FASE_6_TEST_PLAN.md` §12 verdes; matriz de flags acima validada nos três estados combinados.

## 6.9 — Controllers

**Escopo:** `ReconciliationClosureController` (`create`, `store`, `history`/`index`, `show`, `reopenForm`, `reopen`) + form requests (`StoreReconciliationClosureRequest`, `ReopenReconciliationClosureRequest` — mesmo padrão de `VoidReconciliationMatchRequest`).

**Critério de aceite:** rotas registradas em `routes/web.php` dentro do grupo `reconciliacao-v2` existente, com `middleware('reconciliation.closing')` e `can:reconciliation:*` por rota, seguindo exatamente o padrão já usado para `matching`/`candidates`/`exceptions`.

## 6.10 — UI

**Escopo:** as 5 telas de `FASE_6_UI_UX.md` §2, extensão de `show.blade.php`.

**Critério de aceite:** navegação manual (via `run`/browser) confirma o fluxo completo: sessão → preparar fechamento → confirmar → histórico → reabrir → novo fechamento; nenhuma tela existente (`/reconciliacao-v2` demais rotas, `/conciliacoes`, Contas a Pagar/Receber) muda visualmente.

## 6.11 — Testes

**Escopo:** implementar integralmente `FASE_6_TEST_PLAN.md` — SQLite primeiro, depois réplica em `tests/Homologation/` para MariaDB (schema §18 e concorrência §15).

**Critério de aceite:** suíte SQLite verde (93 + novos); suíte MariaDB verde quando ambiente disponível; nenhuma regressão nas Fases 1–5.

## 6.12 — Docs

**Escopo:**
- `docs/architecture/ADR-01X-reconciliation-closure-model.md` (documentando a decisão híbrida, análogo a ADR-009);
- `docs/architecture/ADR-01Y-reconciliation-closure-policy.md` (documentando as respostas de `FASE_6_PERGUNTAS_NEGOCIO.md`, uma vez obtidas);
- `gestao-modern/IMPLEMENTACAO_FASE_6.md` (mesmo formato de `IMPLEMENTACAO_FASE_1.md`...`FASE_5.md`);
- atualizar `docs/api/openapi-v1.yaml` **somente se** a Fase 6 expuser endpoints na API V1 (não previsto neste plano — a superfície da Fase 6 é a UI web, como Fase 4/5).

**Critério de aceite:** documentos revisados e coerentes com o código efetivamente implementado (não com este plano — se a implementação divergir da proposta, os ADRs devem refletir o que foi de fato construído).

---

## Métricas do fechamento — quais são calculáveis agora vs. dependem de negócio

| Métrica (`metric_key`) | Calculável com o domínio atual? | Depende de negócio? |
|---|:---:|---|
| `bank_transactions_count` | sim | — |
| `credit_total` | sim | — |
| `debit_total` | sim | — |
| `titles_count` | sim | — |
| `reconciled_amount` | sim | — |
| `unreconciled_amount` | sim (por diferença) | tolerância aceitável (pergunta 13) |
| `matches_manual_count` | sim | — |
| `matches_assisted_count` (aceite de candidato) | sim | — |
| `exceptions_justified_count` | sim | — |
| `exceptions_open_count` | sim | política de bloqueio associada (pergunta 3) |
| `reconciliation_rate` | sim (derivada) | definição de fórmula oficial (percentual sobre crédito, sobre total de títulos, etc. — não assumir sem confirmar) |
| `opening_balance` / `closing_balance` | **não** — domínio moderno não representa saldo bancário hoje | pergunta 9 |
| `matches_automatic_count` (auto-match) | não aplicável — auto-match está fora de escopo (ADR-011, `FASE_6_ARQUITETURA.md` §8) | fora de escopo |

Todas as métricas "sim" podem ser implementadas em `6.3` sem esperar resposta de negócio. As demais ficam com `metric_value = null` documentado, nunca com um valor inventado.

## Ordem de dependência resumida

```text
6.1 (migrations) ──┬──> 6.2 (domain) ──> 6.3 (validator/snapshot/hash) ──> 6.4 (service + lock)
                    │                                                          │
                    └──────────────────────────────────────────────────────────┤
                                                                                 ├──> 6.5 (reopen)
                                                                                 ├──> 6.6 (audit)
6.7 (RBAC) ─────────────────────────────────────────────────────────────────────┤
6.8 (flag) ──────────────────────────────────────────────────────────────────────┤
                                                                                    ├──> 6.9 (controllers)
                                                                                    └──> 6.10 (UI)
6.9 + 6.10 ──> 6.11 (testes end-to-end) ──> 6.12 (docs)
```

6.7 e 6.8 podem ser feitos em paralelo com 6.2–6.6 (não têm dependência entre si). 6.11 (testes unitários/de serviço) na verdade começa junto com 6.3–6.6, não é uma etapa isolada ao final — a tabela acima descreve dependência de **integração**, não proíbe TDD dentro de cada etapa.
