# Fase 6 — Plano de testes

- Status: **proposta, não implementada**. Nenhum teste real foi criado.
- Convenção seguida: `tests/Feature/ReconciliationV2Test.php`, `tests/Feature/ReconciliationMatchingTest.php` (SQLite, síncrono) + `tests/Homologation/MariaDbSchemaHomologationTest.php`/`MariaDbConcurrencyHomologationTest.php` (MariaDB 10.1, processos independentes via `tools/homologation/concurrency-worker.php`).

Todo teste abaixo deve rodar primeiro em SQLite (`phpunit.xml`, ciclo normal) e depois ser replicado na suíte MariaDB (`phpunit.mariadb.xml`) antes de qualquer `GO`. Nenhum teste SQLite pode ser apresentado como prova de comportamento de concorrência real (regra já estabelecida nos pacotes de homologação anteriores).

## 1. Fechamento válido

- `close()` numa sessão `IN_REVIEW`, sem exceções `OPEN`, sem sobreposição de período → cria `reconciliation_closures` com `status=CLOSED`, `sequence_number=1`, `previous_closure_id=null`; sessão vira `CLOSED`; `reconciliation_closure_matches`/`reconciliation_closure_exceptions`/`reconciliation_closure_metrics` populadas corretamente; `audit_events` com `RECONCILIATION_CLOSURE_CREATED` e `RECONCILIATION_CLOSURE_COMPLETED`.
- Fechamento de sessão `OPEN` sem nenhum match (caso extraordinário) — comportamento depende da resposta de negócio (`FASE_6_PERGUNTAS_NEGOCIO.md`); testar os dois ramos (permitido gera `CLOSURE_EMPTY_SESSION` como warning; proibido gera blocker) e habilitar apenas o aprovado.

## 2. Double-close

- Duas chamadas sequenciais de `close()` na mesma sessão → segunda falha com `CLOSURE_SESSION_ALREADY_CLOSED`; nenhuma segunda linha em `reconciliation_closures`.
- Duas chamadas **concorrentes** (processos independentes, como `MariaDbConcurrencyHomologationTest`) → exatamente uma sessão de `reconciliation_closures` criada com `sequence_number=1`; a outra falha de forma limpa (sem deadlock não tratado, sem exceção não capturada).

## 3. Fechamento com divergência

- Sessão com exceção `OPEN` → sob política Governada (default), `close()` falha com `CLOSURE_OPEN_EXCEPTIONS`.
- Sessão com exceção `JUSTIFIED` → `close()` funciona; a exceção aparece em `reconciliation_closure_exceptions` com `captured_status=JUSTIFIED`.
- Sessão com exceção `RESOLVED` → `close()` funciona; aparece com `captured_status=RESOLVED`.
- (Somente se a política Extraordinária for aprovada) fechamento forçado com exceção `OPEN` e `reconciliation:admin` → funciona e registra o motivo extraordinário; sem a permissão, falha mesmo com a flag ligada.

## 4. Fechamento com match parcial

- Sessão com um título parcialmente conciliado (parte do valor ainda disponível) → `close()` funciona normalmente; a métrica `unreconciled_amount`/`reconciled_amount` reflete a parcialidade corretamente (round-trip de centavos, sem `float`).
- Sessão com título 100% conciliado via múltiplos matches N:N → todos os matches aparecem em `reconciliation_closure_matches`; soma de `captured_total_amount` bate com o valor do título.

## 5. Sobreposição de período

- Conta com fechamento `CLOSED` de 01/08–31/08 → tentar fechar outra sessão da mesma conta com período 15/08–15/09 → falha com `CLOSURE_PERIOD_OVERLAP`.
- Mesmo cenário, mas o fechamento existente está `REOPENED` (não `CLOSED`) → **não** bloqueia (só fechamentos `CLOSED` contam como autoridade vigente) — testar explicitamente esse caso para não travar reaberturas legítimas.
- Períodos adjacentes sem sobreposição (01/08–31/08 e 01/09–30/09) → não bloqueia.

## 6. Hash determinístico

- Mesma sessão, mesmos dados, dois cálculos independentes de `ReconciliationClosureHashService` → hashes idênticos (teste unitário puro, sem banco).
- Alterar a ordem de inserção dos matches na coleção de entrada → hash idêntico (prova de que a ordenação por ID elimina a dependência de ordem).
- Alterar um `captured_status` de um item → hash diferente.
- Alterar `schema_version` ou `engine_version` mantendo o resto idêntico → hash diferente (prova de que a versão faz parte do conteúdo hasheado).
- Recalcular o hash a partir do `snapshot_payload` persistido de um fechamento real (fixture) e comparar com `closure_hash` armazenado → devem bater (teste de integridade fim a fim).

## 7. Imutabilidade

- Após `close()`, tentar (via teste, chamando o Eloquent model diretamente, não via serviço público) `update()` em `snapshot_payload`/`closure_hash`/`period_start`/`period_end`/`closed_by`/`closed_at` de uma `reconciliation_closures` existente e then recalcular o hash a partir do payload persistido → deve continuar batendo, provando que nenhum caminho de aplicação escreve nesses campos após a criação. (Este teste documenta uma invariante de processo, não uma constraint de banco — MariaDB 10.1 não impede `UPDATE` arbitrário; a garantia é de disciplina de serviço, testada por regressão.)
- Nenhum serviço da Fase 6 deve conter `->update([...])` tocando essas colunas fora do método `create()` inicial — pode ser verificado por teste estático simples (grep) ou por revisão manual documentada no PR.

## 8. Reopen

- `reopen()` de um fechamento `CLOSED` com motivo válido e `reconciliation:reopen` → `reconciliation_closures.status=REOPENED`, `reconciliation_sessions.status=REOPENED`, nova linha em `reconciliation_reopenings` com `reason`, `reopened_by`, `previous_status=CLOSED`, `resulting_session_status=REOPENED`; `audit_events` com `RECONCILIATION_CLOSURE_REOPENED`.
- `reopen()` de um fechamento já `REOPENED` → falha com `CLOSURE_NOT_CLOSED`.
- `reopen()` de um `closure_id` que não pertence à sessão informada na rota → falha (mesmo padrão de `RECONCILIATION_MATCH_NOT_FOUND` em `void()`).
- Ciclo completo: `close()` → `reopen()` → novos matches → `close()` de novo → `reconciliation_closures` tem duas linhas, `sequence_number` 1 e 2, `previous_closure_id` da segunda aponta para a primeira; ambos os `closure_hash` permanecem válidos e diferentes entre si.

## 9. Motivo obrigatório

- `reopen()` sem `reason` ou com string vazia/só espaços → falha de validação (422 na camada HTTP, `ReconciliationRuleViolation` na camada de serviço) — mesmo padrão de `VoidReconciliationMatchRequest`.
- `reason` com mais de 1000 caracteres → falha de validação.
- `reason` com exatamente 1 e exatamente 1000 caracteres → aceito (limites do teste, mesmo padrão do `void_reason`).

## 10. RBAC

- Usuário sem `reconciliation:close` → rota de fechamento retorna 403; `ReconciliationClosureService::close()` não é sequer chamado (teste de rota) e, se chamado diretamente (teste de serviço), a autorização em si é responsabilidade do Gate/middleware — o serviço confia no ator autenticado que a controller já validou, seguindo o mesmo padrão de `ManualReconciliationService::confirm()` hoje (autorização na camada HTTP, `assertActor` no serviço só valida presença, não permissão).
- Usuário sem `reconciliation:reopen` → 403 na rota de reabertura.
- Usuário com `reconciliation:view` mas sem `reconciliation:manage`/`close`/`reopen` → consegue ver histórico e detalhe de fechamento, não consegue ver os botões de ação (teste de view, `@cannot`).
- Usuário com `reconciliation:admin` → consegue as ações extraordinárias, se a flag correspondente estiver ligada; com a flag desligada, mesmo `admin` não consegue (kill switch tem prioridade sobre permissão).

## 11. Segregação de funções

- (Somente se a política "ator diferente" for aprovada, ver `FASE_6_RBAC.md` §3) mesmo usuário com `close` e `reopen` tenta reabrir o próprio fechamento → falha com `CLOSURE_REOPEN_SAME_ACTOR_FORBIDDEN`.
- Usuário A (`manage`, sem `close`) confirma matches; usuário B (`close`, sem `manage`) fecha a sessão criada/trabalhada por A → funciona; `closed_by` registra B, matches mantêm `confirmed_by` de A.

## 12. Feature flag

- `RECONCILIATION_CLOSING_ENABLED=false` (default) → rotas de fechamento retornam 404 (mesmo padrão de `EnsureReconciliationV2Enabled`); nenhuma ação de fechamento é acessível mesmo com todas as permissões corretas.
- `RECONCILIATION_CLOSING_ENABLED=true` mas `RECONCILIATION_V2_ENABLED=false` → closing também deve ficar indisponível (closing depende de V2, nunca existe isolado — ver matriz em `FASE_6_IMPLEMENTATION_PLAN.md` §28).
- `RECONCILIATION_CLOSING_ENABLED=true`, `RECONCILIATION_V2_ENABLED=true`, `RECONCILIATION_MATCHING_ENABLED=false` → fechamento funciona normalmente (closing não depende de matching, só de V2 — o pedido original confirma isso em §28).

## 13. IDOR

- Usuário autenticado com `reconciliation:close` tenta fechar/consultar/reabrir uma sessão/fechamento de outra conta sem ter sido explicitamente vinculado (dado que o modelo de permissão atual é por *allowlist global de usuário*, não por conta) → **isto expõe uma lacuna real do modelo atual**: hoje `reconciliation:manage` já concede acesso a todas as contas, não há escopo por conta. A Fase 6 herda essa mesma limitação — não a piora, mas também não a resolve. Documentar como pendência (ver `FASE_6_PERGUNTAS_NEGOCIO.md`) em vez de inventar um escopo por conta não pedido.
- Rotas com `{session}`/`{closure}` devem usar route-model binding padrão do Laravel (como já ocorre) e validar que o `closure` pertence ao `session` da URL (mesmo padrão de `showMatch`/`voidMatch` hoje) — teste de acesso cruzado (`closure` de outra sessão na URL) deve retornar 404, não 200 com dado errado.

## 14. CSRF

- Todas as rotas `POST` de fechamento/reabertura passam pelo middleware padrão `web` (CSRF do Laravel) — teste de requisição sem token válido retorna 419, igual a `matches.store`/`matches.void` hoje. Nenhuma rota de escrita da Fase 6 fica fora do grupo `web`.

## 15. Concorrência (processos independentes, replicando `MariaDbConcurrencyHomologationTest`)

| Cenário | Resultado esperado |
|---|---|
| Dois usuários tentam `close()` a mesma sessão simultaneamente | exatamente um sucesso; o outro falha com `CLOSURE_SESSION_ALREADY_CLOSED` sem exceção não tratada |
| Um fecha enquanto outro cria match na mesma sessão | serializado pelo lock da sessão (ADR-009); ou o match entra antes do fechamento (e é incluído no snapshot) ou o fechamento vence primeiro e o match falha com `RECONCILIATION_SESSION_CLOSED` — nunca os dois sucedem de forma inconsistente |
| Um fecha enquanto outro executa `void()` de um match da sessão | mesmo racional acima — nunca um fechamento com `snapshot_payload` que já não reflita o void, nem um void aceito sobre sessão já fechada |
| Um reabre enquanto outro consulta (`show`/histórico) | leitura nunca trava; pode ver o estado antes ou depois da reabertura, nunca um estado inconsistente (parcialmente atualizado) |
| Dois usuários tentam `reopen()` o mesmo fechamento | exatamente um sucesso; o outro falha com `CLOSURE_NOT_CLOSED` |
| Fechamento de duas sessões da mesma conta com períodos sobrepostos, simultaneamente | no máximo um dos dois fecha com sucesso; o outro falha com `CLOSURE_PERIOD_OVERLAP` — nunca os dois `CLOSED` ao mesmo tempo |

Nenhuma execução sequencial pode ser apresentada como prova de concorrência real — mesma regra já aplicada às Fases 4/5.

## 16. Audit

- Cada transição relevante (`close`, `reopen`) produz exatamente um `audit_events` coerente com `correlation_id` propagado do request (`EnsureCorrelationId`), `before_state`/`after_state` populados como nas Fases 1–5.
- `RECONCILIATION_CLOSURE_EXPORT_GENERATED` é registrado mesmo quando a exportação não altera dado nenhum (evento somente informativo, sem `before`/`after` de conteúdo — apenas metadados de quem exportou o quê e quando).

## 17. Regressão das Fases 1–5

- Suíte completa (93 testes SQLite atuais + os novos) continua passando sem alteração de comportamento de: núcleo financeiro, API V1, importação bancária/OFX, conciliação manual (Fase 4), matching assistido (Fase 5), conciliação legada (`/conciliacoes`).
- Com `RECONCILIATION_CLOSING_ENABLED=false`, todo o comportamento das Fases 1–5 permanece **byte-a-byte idêntico** ao atual — nenhuma rota, view ou resposta existente muda por causa da presença do código da Fase 6 desligado.

## 18. Testes de schema MariaDB (extensão de `MariaDbSchemaHomologationTable`)

- As 5 novas tabelas existem com engine `InnoDB`, `utf8mb4`, e os tipos exatos especificados em `FASE_6_MODELO_DADOS.md` (`DECIMAL(15,2)`, `LONGTEXT`, `CHAR(64)`);
- todas as FKs novas usam `RESTRICT` (nenhuma `CASCADE`/`SET NULL` foi especificada nesta fase — nenhuma delas deve ser adicionada sem justificativa nova);
- `UP` → `DOWN` → `UP` roda limpo para as 5 migrations novas, na mesma bateria do runbook existente;
- índices únicos (`recon_closures_session_seq_uq`, `recon_closure_matches_uq`, `recon_closure_exceptions_uq`, `recon_closure_metrics_uq`) rejeitam duplicata via `QueryException` código `23000`, testado explicitamente (mesmo padrão já usado em `ReconciliationSessionService` para `RECONCILIATION_SESSION_DUPLICATE`).

## 19. Não incluído neste plano

Testes de auto-match, baixa automática, Open Finance, ou qualquer item listado em `FASE_6_ARQUITETURA.md` §8 — fora de escopo da Fase 6.
