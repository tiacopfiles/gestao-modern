# Auditoria final do projeto — `gestao-modern`

**Data:** 14/08/2026
**Método:** leitura direta do código real (não apenas dos relatórios `IMPLEMENTACAO_FASE_*.md`/`PACOTE_CONTINUACAO_*.md`), execução dos comandos de baseline, e uma auditoria de segurança pontual (CSRF, mass assignment, IDOR, raw SQL, XXE, secrets). Onde a documentação anterior divergia do código, o código é a autoridade — divergências estão marcadas explicitamente.

## Baseline executado agora

```text
PHP 8.2.12 (cli), Composer 2.9.5
git status: repositório sem nenhum commit desde o início do projeto (esperado — nunca foi pedido commit)
php artisan test --compact  → 111 passed (652 assertions), 5.19s
php artisan route:list      → 91 rotas totais da aplicação; 13 api/v1; 18 reconciliacao-v2
php artisan migrate:status  → 26 migrations, todas "Ran" (batches 1–3)
php artisan migrate --pretend → "Nothing to migrate" (nenhuma migration pendente localmente)
vendor/bin/pint --test      → passed
composer validate --strict  → válido
composer audit              → sem vulnerabilidades conhecidas nas dependências
```

Confere com o baseline informado (111/652). Nenhuma regressão.

## Classificação por área

| Área | Status | Evidência / observação |
|---|---|---|
| Núcleo financeiro (Fase 1) — `financial_titles`, `title_installments`, `title_settlements`, `source_systems`, `audit_events` | **IMPLEMENTADO** | Migrations `2026_08_12_000001`–`2026_08_13_000080/090` presentes e `Ran`; testes de ingestão/parcelamento/liquidação/cancelamento passam na suíte atual. |
| API v1 (Fase 2) — Bearer hash, scopes, `Idempotency-Key`, `correlation_id` | **IMPLEMENTADO** | 13 operações confirmadas via `route:list --path=api/v1`; `EnsureIdempotentRequest`, `RequireIntegrationScope`, `AuthenticateIntegrationClient` presentes; `FinancialTitleController::findTitle` escopa por `source_system_id` do cliente autenticado (sem IDOR entre integrações). |
| Importação bancária/OFX (Fase 3) — `bank_transactions`, `import_batches`, dedupe por identidade forte | **IMPLEMENTADO** | Parser OFX é regex-based (não usa `SimpleXMLElement`/`DOMDocument`), e rejeita explicitamente `<!DOCTYPE`/`<!ENTITY`/`SYSTEM`/`PUBLIC` antes de processar (`OfxBankStatementImporter.php:23`) — mitigação de XXE mesmo sem parser XML real. |
| Conciliação manual persistente (Fase 4) — sessões, matches 1:1/1:N/N:1/N:N, void | **IMPLEMENTADO** | `ManualReconciliationService` trava sessão/transações/títulos/parcelas em ordem estável por ID antes de validar; testado em `ReconciliationV2Test.php`. |
| Matching assistido (Fase 5) — candidatos, score, exceptions | **IMPLEMENTADO** | `ReconciliationMatchingEngine`, `ReconciliationCandidateService`, `ReconciliationExceptionService`; sem auto-match (aceite sempre revalida via `ManualReconciliationService::confirm`). |
| Fechamento e governança (Fase 6) — closure, snapshot, hash, reopen | **IMPLEMENTADO** (desenvolvimento; homologação pendente) | `ReconciliationClosureService::close()`/`ReconciliationReopeningService::reopen()`; 18 testes dedicados; ver `IMPLEMENTACAO_FASE_6.md`. |
| Bloqueio de mutações pós-fechamento | **IMPLEMENTADO** | `assertSessionOpenForWrite` aplicado em `ManualReconciliationService::confirm/void`, `ReconciliationCandidateService::accept/reject`, `ReconciliationExceptionService::justify`, `ReconciliationMatchingEngine::generate`. Testado explicitamente (`test_writes_are_blocked_after_session_is_closed`, `test_candidate_accept_and_reject_are_blocked_after_close`). |
| Sobreposição de período no fechamento | **IMPLEMENTADO** | Bloqueia contra outro fechamento `CLOSED` da mesma conta; não bloqueia contra `REOPENED` (testado). |
| RBAC | **IMPLEMENTADO, com dívida técnica documentada** | `Gate::define` por allowlist de `config()`/`env()` — sem tabela de papéis. Ver seção de dívida técnica abaixo. |
| Feature flags (`v2`, `matching`, `closing`) | **IMPLEMENTADO** | Matriz de dependência (`closing` depende de `v2`, independente de `matching`) testada explicitamente em `ReconciliationClosureTest`. Todas `false` por padrão. |
| Auditoria (`audit_events`) | **IMPLEMENTADO** | `DatabaseAuditEventRecorder` usado de forma consistente por todos os serviços de Fases 1–6 (`before_state`/`after_state`, `correlation_id`, ator). Existe também um sistema de auditoria legado separado (`App\Services\Audit` → tabela própria, usado só pelos controllers legados `/conciliacoes`, `/contas-a-pagar`, etc.) — dois sistemas paralelos e intencionalmente não unificados (o legado não deve ser tocado). |
| Segurança (CSRF/mass assignment/IDOR/raw SQL/XXE) | **IMPLEMENTADO, sem achado crítico novo** | Ver seção de segurança abaixo. |
| Homologação MariaDB 10.1 | **BLOQUEADO EXTERNAMENTE** | Ver `HOMOLOGACAO_MARIADB_FINAL.md`. Docker CLI está instalado nesta máquina (novidade em relação às sessões anteriores), mas o daemon não está em execução — verificado de forma não invasiva, não iniciado. |
| Decisões de negócio da Fase 6 (14 perguntas) | **DECISÃO DE NEGÓCIO NECESSÁRIA** | Ver `PENDENCIAS_NEGOCIO_FINAIS.md`. Defaults seguros documentados em ADR-015, nenhum inventado. |
| Exportação de relatório de fechamento | **PENDENTE** | Gate `reconciliation:export` existe; nenhum endpoint de exportação foi implementado (formato não definido pelo negócio). |
| Documentação | **IMPLEMENTADO** | ADR-001 a ADR-015, 7 runbooks de operação, `IMPLEMENTACAO_FASE_1..6.md`, este pacote de finalização. |

## Dívida técnica documentada (não corrigida nesta finalização, por decisão deliberada)

1. **RBAC por allowlist, não por papel persistente.** `app/Providers/AppServiceProvider.php` define 8 gates (`payments`, `commercial`, `reconciliation:view/manage/close/reopen/export/admin`) todos por lista de IDs em `config()`/`env()`. Funciona corretamente para o volume atual de usuários, mas não escala para dezenas de perfis nem oferece auditoria de "quem tinha qual permissão quando" além do que já está em `.env`/deploy history. Plano futuro: uma tabela `roles`/`permissions` com histórico, fora do escopo desta finalização (mudança de arquitetura, não bug).
2. **IDOR conhecido e não resolvido em `reconciliation:manage`/`close`/`reopen`.** O modelo de permissão é por *allowlist global de usuário*, não por conta — um usuário com `reconciliation:manage` acessa todas as contas. Documentado desde a Fase 6 (`FASE_6_TEST_PLAN.md` §13); não é uma regressão desta finalização nem foi piorado.
3. **`LegacyModel` usa `$guarded = []`** (mass assignment tecnicamente irrestrito no nível do model) para todos os models legados (`Conciliacao`, `Lancamento`, `Recebimento`, `Movimento`, `Cliente`, `Fornecedor`, `Conta`, etc.), compensado por `$request->validate([...])` explícito em todo controller legado que grava dados — verificado em `ReconciliationController::store/update` e nos demais. Nenhum caminho usando `$request->all()` diretamente foi encontrado. Risco baixo, mas frágil a mudanças futuras que não sigam o mesmo padrão de disciplina.
4. **Dois sistemas de auditoria paralelos** (legado `App\Services\Audit` vs. moderno `audit_events`), por design — não deve ser unificado sem migração completa do legado, fora de escopo.

## Achados de segurança (auditoria pontual)

- CSRF: nenhuma rota web possui exceção de `VerifyCsrfToken`; todas as mutações passam pelo grupo `web` padrão do Laravel.
- Mass assignment: modelos modernos (Fase 1–6) usam `$fillable` explícito em todos os casos revisados; nenhum campo controlado pelo servidor (`status`, `created_by`, `correlation_id`, etc.) é aceito do cliente sem whitelist de FormRequest (confirmado também por teste existente `test_web_session_contract_rejects_server_controlled_fields`).
- Raw SQL: apenas 2 ocorrências de `selectRaw`, ambas agregações `COUNT(*)` sem interpolação de entrada do usuário (`OfxImportService.php`).
- XXE: parser OFX não usa parser XML; rejeita DOCTYPE/ENTITY/SYSTEM/PUBLIC antes de qualquer processamento.
- IDOR na API v1: `external_id` sempre escopado por `source_system_id` do cliente autenticado.
- Segredos: `.env` real existe em `gestao-modern/.env` (não commitado, excluído do pacote final — ver `PACOTE_FINAL` no relatório de finalização).

Nenhum achado crítico ou de alta severidade nesta passagem. Não foi executado um scanner SAST dedicado (fora do escopo desta finalização); recomenda-se antes de produção real.

## Divergências entre documentação anterior e código real

Nenhuma divergência estrutural foi encontrada nesta auditoria (mesma conclusão da auditoria pré-Fase 6). Pontos cosméticos já registrados anteriormente (contagem de rotas variando entre pacotes por incluírem ou não rotas fora de `api/v1`/`reconciliacao-v2`) continuam sem impacto funcional.
