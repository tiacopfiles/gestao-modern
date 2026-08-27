# Pre-production readiness — final (Fases 1–6)

Status atual: **NÃO AUTORIZADO**. Este documento substitui/estende `PRE-PRODUCTION-READINESS.md` (que cobria só até a Fase 5) com o estado real após a Fase 6. Continua sendo um checklist para um futuro em que os bloqueios abaixo tenham sido removidos — não uma autorização.

## Gate de entrada (todos obrigatórios, nenhum dispensável)

1. Homologação MariaDB 10.1 das Fases 1–6 concluída com evidência real (ver `HOMOLOGACAO_MARIADB_FINAL.md`) — **hoje: NÃO, bloqueado por infraestrutura**.
2. As 14 perguntas de `PENDENCIAS_NEGOCIO_FINAIS.md` respondidas pelo financeiro, cada uma com ADR registrado — **hoje: NÃO**.
3. Aprovação explícita dos responsáveis técnico, negócio, infraestrutura e segurança — **hoje: NÃO solicitada**.
4. Nenhuma pendência crítica de centavos, idempotência, concorrência, autorização ou legado — regressão local (111/652) verde, mas concorrência real (item 1) não comprovada.

## Checklist obrigatório antes de qualquer deploy real

1. Definir responsável nominal pela implantação e canal de decisão (pessoa, não cargo).
2. Confirmar backup íntegro do banco alvo, testado (restauração cronometrada e verificada, não apenas "backup existe").
3. Inspecionar o schema real de produção somente em janela/autorização apropriada: versão do MariaDB, engine, charset, collation, índices, FKs e nomes de tabela/coluna conflitantes com as 26 migrations deste projeto.
4. Executar `php artisan migrate --pretend` no ambiente controlado e revisar **todo** o SQL gerado; nunca usar esse comando isoladamente como prova de segurança.
5. Definir janela de implantação, congelamento de alterações concorrentes no banco e comunicação aos usuários do legado.
6. Publicar código com **todas** as flags desligadas:
   ```dotenv
   RECONCILIATION_V2_ENABLED=false
   RECONCILIATION_MATCHING_ENABLED=false
   RECONCILIATION_CLOSING_ENABLED=false
   ```
7. Aplicar as 26 migrations de forma controlada, com monitoramento ativo e critério de abortar antes de qualquer passo irreversível (nenhuma delas altera/dropa dados existentes — todas são `CREATE TABLE` aditivas).
8. Executar smoke tests pós-deploy: autenticação, API v1 (13 operações), núcleo financeiro, importação OFX, `/conciliacoes` (legado), Contas a Pagar/Receber (legado) — todos com as flags ainda desligadas.
9. Confirmar observabilidade mínima antes de ligar qualquer flag (ver seção própria abaixo).
10. Ter pronto o plano de rollback de código e o plano de recuperação de banco (`ROLLBACK_PLAN_FINAL.md`) — migrations com dados reais gravados exigem estratégia própria, nunca `migrate:rollback`/`migrate:fresh` casual.
11. Habilitar `RECONCILIATION_V2_ENABLED=true` (só manual, matching e closing continuam `false`) apenas para grupo limitado e nomeado de usuários (`RECONCILIATION_V2_VIEW_USER_IDS`/`MANAGE_USER_IDS`).
12. Validar operação, auditoria (`audit_events`), segregação de acesso e ausência de qualquer efeito no legado durante um período de observação antes de ampliar.
13. Habilitar `RECONCILIATION_MATCHING_ENABLED=true` apenas para o mesmo grupo limitado; nunca ligar auto-match (não existe no código, e não deve ser adicionado).
14. Só depois de operação estável do manual+matching, avaliar `RECONCILIATION_CLOSING_ENABLED=true` — exige que as pendências de negócio da Fase 6 estejam resolvidas (four-eyes, prazo, política de divergência, etc. — o que o negócio decidir).
15. Reavaliar critérios antes de ampliar público ou volume em qualquer etapa.

## Observabilidade recomendada antes de produção

- **Logs:** todos os serviços de Fases 1–6 já emitem `Log::info` estruturado com `correlation_id`, ator e ação (`reconciliation_v2_operation`, `reconciliation_matching_operation`, `reconciliation_closure_operation`, `integration_api_request`). Recomenda-se agregação centralizada (ex.: ELK/Loki) antes de produção — não configurada neste projeto.
- **Métricas recomendadas:** taxa de erro por rota (`api/v1/*`, `/reconciliacao-v2/*`), latência p95/p99 de `close()`/`generate()` (operações mais pesadas), contagem de `ReconciliationRuleViolation` por código de regra (sinaliza fricção de UX ou tentativa indevida), deadlocks/timeouts de transação (crítico em MariaDB sob concorrência real).
- **Alertas recomendados:** erro 500 sustentado em `/api/v1/*`; falha de migration; escrita inesperada em qualquer tabela `avt_*` (deveria ser estruturalmente impossível — um alerta aqui indica bug grave); volume anômalo de `RECONCILIATION_SESSION_CLOSED` (pode indicar tentativa de burlar um fechamento).
- Nenhum SLA numérico é proposto aqui — depende de volume real de produção, não inventado.

## Estratégia de ativação futura (resumo)

```text
deploy de código (flags OFF)
→ migrations controladas
→ smoke tests e observabilidade mínima confirmada
→ V2 manual para grupo limitado
→ validação operacional
→ matching assistido para grupo limitado
→ closing (após pendências de negócio resolvidas) para grupo limitado
→ ampliação gradual
```

Não desativar `/conciliacoes` nem Contas a Pagar/Receber em nenhuma etapa — ver `PLANO_OPERACAO_PARALELA.md`.

## Critérios de aborto

Abortar e acionar `ROLLBACK_PLAN_FINAL.md` se houver:

- versão/schema divergente do homologado;
- SQL inesperado ou lock prolongado durante migration;
- erro de migration ou violação de constraint;
- perda ou arredondamento de centavos;
- duplicação, over-allocation ou double match/close;
- regressão na API v1 ou no legado;
- flag ineficaz (rota acessível com flag `false`) ou falha crítica de autorização;
- qualquer escrita, mesmo acidental, em tabela legada protegida;
- observabilidade insuficiente para decidir com segurança.

## Evidências e responsabilidades

Guardar aprovações, hashes dos artefatos (`MANIFEST_SHA256.txt` do pacote final), backup, saída sanitizada de migrations/smoke tests, métricas, horários e responsáveis por cada decisão GO/NO-GO. Nenhuma senha, token, dado financeiro real ou dump de produção deve entrar em qualquer pacote documental ou repositório.
