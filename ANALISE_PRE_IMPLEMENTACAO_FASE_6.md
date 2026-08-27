# Análise pré-implementação — Fase 6 (Fechamento e Governança)

Data: 2026-08-14. Este documento é o checkpoint obrigatório antes de qualquer alteração de código para a Fase 6, conforme protocolo do projeto. Produzido após leitura direta do código real (não apenas dos pacotes de continuidade anteriores) e dos 9 documentos de design em `docs/phase-6-design/`.

## 1. Decisão de gating — por que a implementação começa agora

O pacote mais recente (`../ACOP_FASE_5_5C_NO_GO_2026-08-14_073000.zip`, `PACOTE_CONTINUACAO_FASE_5_5C_NO_GO.md`) registra **NO-GO / BLOCKED** para a homologação MariaDB 10.1, e conclui "NÃO INICIAR A FASE 6". O arquivo `PACOTE_CONTINUACAO_FASE_5_5C_GO.md` (pré-condição citada pelos 9 documentos de design) **não existe** — não houve GO formal.

A instrução recebida para esta sessão altera explicitamente essa postura: o **desenvolvimento** da Fase 6 pode prosseguir sem aguardar a homologação MariaDB; a **produção** continua bloqueada; a homologação MariaDB continua sendo pendência obrigatória antes de produção. Este documento registra essa mudança de decisão de forma rastreável, sem apagar o histórico NO-GO — o bloqueio de infraestrutura (ausência de Docker/Podman/MariaDB descartável) permanece verdadeiro e não foi resolvido.

```text
DESENVOLVIMENTO FASE 6: autorizado a partir de agora
HOMOLOGAÇÃO MARIADB (Fases 1-5): continua NO-GO/BLOCKED (infraestrutura)
PRODUÇÃO: continua NÃO AUTORIZADA
```

## 2. Baseline real (verificado agora, não copiado de documentação antiga)

```text
php artisan test --compact   → 93 passed (565 assertions), 4.22s
php artisan migrate:status   → 21 migrations, todas "Ran" (2026_08_12/13)
php artisan route:list       → 13 rotas api/v1 + 13 rotas reconciliacao-v2 (83 rotas totais na aplicação)
vendor/bin/pint --test       → passed
composer validate --strict   → válido
composer audit               → sem vulnerabilidades
```

Baseline confere com o documentado em `PACOTE_CONTINUACAO_FASE_5_5C_NO_GO.md` (93/565). Nenhuma regressão pendente.

## 3. Estado real das Fases 1–5 (o que existe, não o que os pacotes descrevem)

**Tabelas** (21 migrations, `2026_08_12_000001` a `2026_08_13_000200`): `documentos_modernos`, `source_systems`, `financial_titles`, `title_installments`, `title_settlements`, `audit_events`, `integration_clients`, `integration_requests`, `title_cancellations`, `import_batches`, `bank_transactions`, `import_batch_items`, `reconciliation_sessions`, `reconciliation_matches`, `reconciliation_match_titles`, `reconciliation_match_transactions`, `reconciliation_candidates`, `reconciliation_candidate_titles`, `reconciliation_candidate_transactions`, `reconciliation_exceptions`.

**Services** (`app/Application/Reconciliation/`): `ManualReconciliationService` (confirm/void, lock ordenado por ID, `DB::transaction(...,3)`), `ReconciliationSessionService`, `ReconciliationAllocationQuery`, `ReconciliationCandidateScorer`, `ReconciliationCandidateService` (accept/reject), `ReconciliationExceptionService` (justify), `ReconciliationFeature`/`ReconciliationMatchingFeature` (kill switches), `ReconciliationMatchingEngine` (generate), `ReconciliationTextNormalizer`.

**Domain** (`app/Domain/Reconciliation/`): enums `ReconciliationSessionStatus` (hoje **apenas** `Open`/`InReview` — `Closed`/`Reopened` não existem, confirmando que a Fase 6 precisa estendê-lo), `ReconciliationMatchStatus` (`Confirmed`/`Voided`), `ReconciliationExceptionStatus` (`Open`/`InReview`/`Resolved`/`Justified`), `ReconciliationCandidateStatus`, `ReconciliationCandidateType`, `ReconciliationExceptionType`, `ReconciliationMethod` (`Manual`); exceção única `ReconciliationRuleViolation(rule, message)`.

**RBAC real** (`app/Providers/AppServiceProvider.php`): `Gate::define` por allowlist simples via `config()`/`env()` — **não existe tabela de papéis**. Hoje só `reconciliation:view`/`reconciliation:manage` (mais `payments`/`commercial`, que usam coluna do usuário legado — padrão mais antigo, não estendido). `config/reconciliation.php` hoje só tem `v2_enabled`, `matching_enabled`, `view_user_ids`, `manage_user_ids`.

**Auditoria real**: `DatabaseAuditEventRecorder` (implementa `AuditEventRecorder`) grava em `audit_events` (`actor_id`, `action`, `entity_type`, `entity_id`, `before_state`/`after_state` como array serializado, `source_system_id`, `integration_client_id`, `correlation_id`, `occurred_at`).

**Legado**: `/conciliacoes` (`ReconciliationController`) intacto. `/reconciliacao-v2` (`ReconciliationV2Controller`, `ReconciliationMatchingController`) com sessões, match manual, candidatos, exceções, geração de matching — nenhuma rota/tela de fechamento ainda.

**Feature flags reais**: `RECONCILIATION_V2_ENABLED=false`, `RECONCILIATION_MATCHING_ENABLED=false` (`.env.example`), aplicadas via middlewares `reconciliation.v2`/`reconciliation.matching` (`EnsureReconciliationV2Enabled`/`EnsureReconciliationMatchingEnabled`, `abort_unless(..., 404)`).

## 4. Design da Fase 6 (`docs/phase-6-design/`) — resumo e avaliação

Os 9 documentos (ARQUITETURA, MODELO_DADOS, STATE_MACHINE, RBAC, UI_UX, TEST_PLAN, PERGUNTAS_NEGOCIO, IMPLEMENTATION_PLAN, PROMPT_CODEX) foram lidos por completo. **Nenhuma divergência estrutural relevante foi encontrada entre o design e o código real** — os documentos citam nomes reais de arquivos/classes/colunas com precisão, confirmando que quem os escreveu já havia inspecionado o código. Único ponto observado: o prompt do Codex assume "86 rotas" de baseline; a contagem real é 83 (rotas totais da aplicação) — diferença cosmética de uma versão anterior de documentação, sem efeito na Fase 6.

Decisões de design já tomadas e adotadas nesta implementação, sem reabrir debate:

- **Modelo de dados**: híbrido (Opção C) — 5 tabelas novas (`reconciliation_closures`, `reconciliation_closure_matches`, `reconciliation_closure_exceptions`, `reconciliation_closure_metrics` como EAV chave/valor, `reconciliation_reopenings`), todas aditivas, `InnoDB`, sem coluna JSON nativa (compatibilidade MariaDB 10.1), `decimal(15,2)`, `char(64)` para hash, `longText` para snapshot canônico.
- **Máquina de estados**: `OPEN → IN_REVIEW → CLOSED → REOPENED → CLOSED...`. `READY_TO_CLOSE` **não é persistido** — calculado sob demanda por `ReconciliationClosureValidator`.
- **Hash**: SHA-256 sobre payload canônico (chaves ordenadas alfabeticamente, listas ordenadas por ID, valores monetários como string decimal fixa, nunca float), com `schema_version`/`engine_version` dentro do hash e `closed_by`/`closed_at`/`correlation_id` fora dele.
- **Bloqueio pós-fechamento**: aplicado na camada de serviço (`assertSessionOpenForWrite`) em `ManualReconciliationService::confirm/void`, `ReconciliationCandidateService::accept/reject`, `ReconciliationExceptionService::justify`, `ReconciliationMatchingEngine::generate` — nunca só na UI/rota.
- **Sobreposição de período**: checagem transacional (`lockForUpdate`) contra outros fechamentos `CLOSED` da mesma conta — sem constraint de banco (MariaDB 10.1 não suporta range check).
- **RBAC**: estende o padrão real (Gate + allowlist `config`/`env`) com `reconciliation:close`/`reopen`/`export`/`admin`. Não introduz tabela de papéis.
- **UI**: reaproveita vocabulário visual de `resources/views/reconciliation-v2/` (record-hero, badge, panel table-panel, empty-state); 5 telas novas; nenhuma tela de `/conciliacoes` ou Contas a Pagar/Receber é tocada.

## 5. Pendências de negócio (14 perguntas, nenhuma respondida, nenhum ADR)

`FASE_6_PERGUNTAS_NEGOCIO.md` lista 14 perguntas sem resposta e sem ADR correspondente (repositório vai até ADR-012). Nenhuma política de fechamento (Rígida/Governada/Extraordinária), segregação ator-fecha≠ator-reabre, saldo de autoridade, four-eyes, prazo de fechamento ou tolerância de saldo foi decidida pelo negócio até o momento desta implementação.

**Decisão para esta implementação**: usar exclusivamente os defaults seguros já documentados pelo próprio design, sem inventar política definitiva:

| Pendência | Default seguro adotado agora |
|---|---|
| Política de divergência aberta (pergunta 3) | **Governada**: `JUSTIFIED`/`RESOLVED` permitem fechar; `OPEN`/`IN_REVIEW` bloqueiam (`CLOSURE_OPEN_EXCEPTIONS`) |
| Política Extraordinária / four-eyes (perguntas 3, 5) | Não implementada; `RECONCILIATION_CLOSURE_FORCE_ENABLED` não criada nesta fase (nenhum código a governar) |
| Candidato `PENDING` bloqueia? (pergunta 12) | Não — `CLOSURE_PENDING_CANDIDATES` é apenas informativo na tela de pré-fechamento, nunca blocker |
| Sessão vazia fechável? (pergunta 14) | Sim, permitida — `CLOSURE_EMPTY_SESSION` é apenas informativo, nunca blocker |
| Saldo de autoridade / tolerância (perguntas 9, 13) | Não implementado — domínio moderno não representa saldo bancário; métrica correspondente fica ausente do payload, documentada como limitação, nunca inventada |
| Mesmo ator fecha e reabre? (pergunta 11) | Permitido — nenhuma checagem `closed_by !== actorId` nesta fase |
| Prazo/janela de reabertura (perguntas 6, 7) | Sem prazo formal nesta fase |
| Escopo por conta/empresa (perguntas 1, 2, 8) | Allowlist global, mesma limitação já existente em `reconciliation:manage` hoje — documentada, não resolvida |
| Formato de exportação (pergunta 10) | Fora do escopo desta implementação — `reconciliation:export`/evento de auditoria ficam preparados, sem endpoint de exportação real |

Essas escolhas devem ser formalizadas em ADR assim que o negócio responder — ver seção de pendências no `IMPLEMENTACAO_FASE_6.md` final.

## 6. Riscos identificados

- **Concorrência real (InnoDB) não testada.** Toda a proteção de `close()`/`reopen()` depende de `lockForUpdate` + `DB::transaction`, cujo comportamento correto sob concorrência real só é comprovável em MariaDB — pendência já conhecida e herdada das Fases 1–5 (não piora nem resolve).
- **Modificação de código existente das Fases 4/5.** A etapa 6.4 (bloqueio pós-fechamento) é a única que toca `ManualReconciliationService`, `ReconciliationCandidateService`, `ReconciliationExceptionService`, `ReconciliationMatchingEngine` — exige regressão completa da suíte antes e depois.
- **IDOR por allowlist global** (já documentado no `FASE_6_TEST_PLAN.md` §13): `reconciliation:manage`/`close`/`reopen` concedem acesso a todas as contas, não há escopo por conta. A Fase 6 herda, não piora.
- **Sem exportação real.** `reconciliation:export` e o evento de auditoria ficam preparados, mas o formato do relatório não foi definido pelo negócio — nenhum endpoint de exportação é implementado agora.

## 7. Plano final de implementação (ordem de `FASE_6_IMPLEMENTATION_PLAN.md`)

```text
6.1  Migrations (5 tabelas + extensão do enum de status)
6.2  Domain (ReconciliationClosureStatus, ReconciliationClosureReadiness, códigos CLOSURE_* em ReconciliationRuleViolation)
6.3  Validator + SnapshotBuilder + HashService (lógica pura, testável sem banco)
6.4  ReconciliationClosureService::close() (orquestração + lock) + assertSessionOpenForWrite em Fases 4/5
6.5  ReconciliationReopeningService::reopen()
6.6  Auditoria (RECONCILIATION_CLOSURE_CREATED/COMPLETED/REOPENED/RECLOSED)
6.7  RBAC (gates close/reopen/export/admin)
6.8  Feature flag RECONCILIATION_CLOSING_ENABLED + middleware
6.9  Controllers + form requests + rotas
6.10 UI (5 telas, extensão de show.blade.php)
6.11 Testes (SQLite; réplica MariaDB fica documentada como pendente, sem infraestrutura para rodar agora)
6.12 Docs (IMPLEMENTACAO_FASE_6.md, ADRs, runbook, pacote de continuidade)
```

Nenhum arquivo em `G:\xampp\htdocs\contas`, `G:\xampp\htdocs\contasareceber`, ou tabelas `avt_*` é acessado. Nenhuma migration roda contra banco real — apenas SQLite local do projeto (`database/database.sqlite`, já usado pela suíte de testes).
