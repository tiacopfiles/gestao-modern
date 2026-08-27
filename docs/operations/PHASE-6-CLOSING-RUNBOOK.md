# Runbook — Fechamento e governança (Fase 6)

## Escopo e segurança

Este runbook opera somente `gestao-modern`. Não autoriza acessar `G:\xampp\htdocs\contas` ou `G:\xampp\htdocs\contasareceber`, alterar tabelas legadas protegidas (`avt_lancamentos`, `avt_recebimentos`, `avt_movimentos`, `avt_conciliacoes`), aplicar migrations em produção ou desativar `/conciliacoes`. A homologação MariaDB 10.1 desta fase segue pendente — ver seção final.

## Flags e permissões

Padrão seguro:

```dotenv
RECONCILIATION_V2_ENABLED=false
RECONCILIATION_MATCHING_ENABLED=false
RECONCILIATION_CLOSING_ENABLED=false
RECONCILIATION_V2_VIEW_USER_IDS=
RECONCILIATION_V2_MANAGE_USER_IDS=
RECONCILIATION_CLOSE_USER_IDS=
RECONCILIATION_REOPEN_USER_IDS=
RECONCILIATION_EXPORT_USER_IDS=
RECONCILIATION_ADMIN_USER_IDS=
```

Matriz de dependência entre flags:

```text
V2=false                              → toda a v2 (incl. fechamento) 404
V2=true, CLOSING=false                → conciliação manual/matching funcionam; fechamento 404
V2=true, CLOSING=true, MATCHING=false → fechamento funciona normalmente (não depende de matching)
```

Desligar `RECONCILIATION_CLOSING_ENABLED` nunca afeta Contas a Pagar/Receber, API v1, importação bancária/OFX, matching (se sua própria flag estiver ligada), conciliação manual ou `/conciliacoes`.

`reconciliation:close` fecha; `reconciliation:reopen` reabre (allowlist independente — nada impede hoje que o mesmo usuário tenha as duas permissões e feche/reabra o mesmo fechamento, ver ADR-015); `reconciliation:export` está preparado para uma exportação futura ainda não implementada; `reconciliation:admin` não tem efeito funcional nesta fase (reservado para uma política Extraordinária ainda não implementada).

## Pré-requisitos e configuração

1. Backup e janela aprovados; ambiente e prefixo confirmados.
2. Revisar SQL de `migrate --pretend` para as 5 migrations novas (`2026_08_14_0000{10,20,30,40,50}`); abortar se houver referência às quatro tabelas protegidas.
3. Homologar as migrations novas e o locking de `close()`/`reopen()` em MariaDB 10.1 descartável antes de habilitar em qualquer ambiente compartilhado (ver `PHASE-5-5-MARIADB-HOMOLOGATION-RUNBOOK.md` — a Fase 6 estende a mesma suíte, não a substitui).
4. Confirmar que as 14 perguntas de `docs/phase-6-design/FASE_6_PERGUNTAS_NEGOCIO.md` foram levadas ao financeiro antes de habilitar em produção; enquanto não respondidas, o sistema opera nos defaults seguros documentados em ADR-015 (política Governada, sem four-eyes, sem prazo, sem segregação ator-fecha≠ator-reabre).

## Operação

- **Preparar fechamento:** na sessão v2 (`OPEN`/`IN_REVIEW`/`REOPENED`), "Preparar fechamento →" mostra métricas calculadas agora (não persistidas) e um checklist de blockers/warnings.
- **Confirmar fechamento:** exige marcar explicitamente a caixa de confirmação antes do `POST`; nunca um clique único. `ReconciliationClosureService::close()` valida, trava sessão + matches + exceptions + outros fechamentos sobrepostos da conta, monta o snapshot canônico, calcula `closure_hash` (SHA-256) e persiste tudo numa única transação atômica.
- **Consultar:** histórico de fechamentos (`/fechamentos`) e detalhe de um fechamento (`/fechamentos/{closure}`) ficam sempre visíveis para quem tem `reconciliation:view`, mesmo após reaberturas.
- **Reabrir:** só um fechamento `CLOSED` pode ser reaberto; exige motivo obrigatório (1–1000 caracteres) e `reconciliation:reopen`. O fechamento original nunca é apagado nem tem hash reescrito — vira `REOPENED` e uma linha em `reconciliation_reopenings` registra o evento.
- **Reclose:** depois de reaberto e com novas alterações, um novo `close()` cria uma segunda linha em `reconciliation_closures` com `sequence_number` incrementado e `previous_closure_id` apontando para a anterior.

## Bloqueios após fechamento

Com a sessão `CLOSED`, o **serviço** (não apenas a UI) bloqueia: novo match (`ManualReconciliationService::confirm`), void de match, aceitar/rejeitar candidato, justificar exceção, gerar novos candidatos (`ReconciliationMatchingEngine::generate`). Todas retornam `ReconciliationRuleViolation('RECONCILIATION_SESSION_CLOSED', ...)`. Reabrir a sessão (`REOPENED`) restaura essas capacidades até o próximo fechamento.

## Auditoria e observabilidade

Eventos: `RECONCILIATION_CLOSURE_CREATED` (início da transação de `close()`), `RECONCILIATION_CLOSURE_COMPLETED` (primeiro fechamento de uma sessão), `RECONCILIATION_CLOSURE_RECLOSED` (fechamento subsequente após reabertura), `RECONCILIATION_CLOSURE_REOPENED`. Todos com `correlation_id`, `before_state`/`after_state` mínimos (nunca o `snapshot_payload` completo — já está em `reconciliation_closures`). Correlacione por sessão, fechamento e `correlation_id`.

## Troubleshooting

- 404 em `/fechamento*`: confira `RECONCILIATION_V2_ENABLED` e `RECONCILIATION_CLOSING_ENABLED` e cache de configuração;
- 403: confira allowlist `close_user_ids`/`reopen_user_ids`;
- `CLOSURE_SESSION_ALREADY_CLOSED`: a sessão já foi fechada por outra requisição — não é bug, é a checagem pós-lock funcionando (double-submit ou concorrência);
- `CLOSURE_OPEN_EXCEPTIONS`: existem divergências `OPEN`/`IN_REVIEW`; justifique-as (Fase 5) antes de fechar — política Governada, ver ADR-015;
- `CLOSURE_PERIOD_OVERLAP`: já existe um fechamento `CLOSED` da mesma conta com período sobreposto; fechamentos `REOPENED` não contam para esse bloqueio;
- `CLOSURE_NOT_CLOSED` ao reabrir: o fechamento já está `REOPENED` — não é possível reabrir duas vezes sem um novo `close()` no meio;
- hash divergente ao recalcular a partir do `snapshot_payload`: investigar imediatamente — nenhum caminho de aplicação deveria alterar essas colunas após a criação (ver ADR-014).

## Kill switch e rollback

Primeira resposta: `RECONCILIATION_CLOSING_ENABLED=false` e reconstrução controlada do cache de config. Isso desliga toda a superfície de fechamento/reabertura, mas preserva conciliação manual, matching, API v1, importação bancária e `/conciliacoes`. Para desligar toda a v2, use `RECONCILIATION_V2_ENABLED=false` (também desliga o fechamento, por dependência).

Não faça rollback de tabelas com dados. Reverta a versão da aplicação mantendo histórico. `down()` das 5 migrations novas é apenas para banco isolado/descartável e apaga as estruturas da Fase 6 em ordem inversa; nunca execute contra um banco com fechamentos reais persistidos.

## Homologação MariaDB — pendência declarada

```text
DESENVOLVIMENTO FASE 6: CONCLUÍDO (SQLite, 111 testes / 652 asserções)
HOMOLOGAÇÃO MARIADB: PENDENTE (sem infraestrutura descartável disponível nesta máquina)
PRODUÇÃO: NÃO AUTORIZADA
```

Antes de habilitar `RECONCILIATION_CLOSING_ENABLED=true` em qualquer ambiente compartilhado ou produtivo, execute a suíte de homologação MariaDB estendida (schema das 5 tabelas novas + concorrência de `close()`/`reopen()` em processos independentes) conforme `PHASE-5-5-MARIADB-HOMOLOGATION-RUNBOOK.md`.
