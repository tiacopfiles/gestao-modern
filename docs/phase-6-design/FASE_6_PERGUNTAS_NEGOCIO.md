# Fase 6 — Perguntas para o negócio/financeiro

Este documento reúne **decisões que o código não pode tomar sozinho**. Cada pergunta aqui bloqueia uma regra específica identificada em `FASE_6_STATE_MACHINE.md` e `FASE_6_RBAC.md`. Nenhuma delas foi respondida ainda — os documentos de design descrevem alternativas e um default seguro provisório, nunca uma política definitiva.

Ao responder, registre a decisão em um novo ADR (`docs/architecture/ADR-01X-reconciliation-closure-policy.md`) antes da implementação — não basta responder aqui, a resposta precisa virar decisão arquitetural rastreável, como todas as outras da base.

---

## 1. Quem pode fechar?

Hoje qualquer usuário listado em `RECONCILIATION_CLOSE_USER_IDS` (proposto) poderia fechar qualquer conta. Isso é adequado, ou o fechamento deve ser restrito por conta/centro de custo?

## 2. Quem pode reabrir?

Da mesma forma, `RECONCILIATION_REOPEN_USER_IDS` (proposto) é uma lista global. É suficiente, ou reabertura exige um papel/aprovação mais restrito que fechamento comum?

## 3. Quem pode fechar com divergência aberta?

Ver `FASE_6_STATE_MACHINE.md` §4 (três políticas: Rígida, Governada, Extraordinária). Qual é a política oficial? Se for Extraordinária, quem tem essa permissão (`reconciliation:admin` é suficiente, ou precisa de um segundo aprovador)?

## 4. Exceção `JUSTIFIED` permite fechar?

A proposta assume que sim (a justificativa humana já registrada em `reconciliation_exceptions.resolution_reason` é suficiente). Isso está correto, ou uma exceção `JUSTIFIED` também precisa de uma segunda aprovação específica para fechamento?

## 5. Fechamento precisa de segunda aprovação (four-eyes)?

Hoje nenhuma ação da Fase 4/5 exige dois atores. O fechamento é financeiramente mais sensível — deve exigir que um segundo usuário confirme antes de valer (aprovação em dois passos), ou a permissão `reconciliation:close` de um único ator é suficiente?

## 6. Qual é a data limite para fechar um período?

Existe um prazo (ex.: até o dia 10 do mês seguinte) depois do qual o fechamento deveria ser bloqueado ou sinalizado como atrasado? Ou não há prazo formal nesta fase?

## 7. É permitido reabrir um mês antigo (ex.: 6 meses atrás)?

A proposta atual não limita a distância temporal de uma reabertura. Deveria haver uma janela máxima (ex.: só os últimos N meses são reabríveis sem aprovação extra)?

## 8. O fechamento é por conta ou por empresa+conta?

O modelo atual (`reconciliation_sessions.account_id`) já delimita por conta única. Confirma-se que o fechamento segue a mesma granularidade, ou é necessário agregar múltiplas contas num único fechamento de "empresa" no futuro? (Se sim, isso é uma mudança de escopo maior, fora desta fase — apenas confirmar que não é esperado agora.)

## 9. Qual saldo é considerado autoridade?

`FASE_6_ARQUITETURA.md` nota que o domínio moderno ainda não tem uma representação clara de "saldo bancário inicial/final" independente do legado. O saldo do fechamento deve vir de onde:
- soma das transações bancárias importadas no período (`bank_transactions`), sem saldo inicial explícito;
- um saldo inicial informado manualmente por fechamento;
- o legado (`avt_movimentos`) — **não recomendado**, pois misturaria sistemas que o projeto mantém deliberadamente separados (ADR-003, ADR-009);
- nenhum saldo é mostrado nesta fase, apenas totais de crédito/débito?

Sem resposta, a Fase 6 documenta saldo inicial/final como pendência e mostra apenas os totais que já são calculáveis com segurança (crédito total, débito total) — ver `FASE_6_MODELO_DADOS.md` §5.

## 10. Quais relatórios/exportações precisam sair do fechamento?

`reconciliation:export` e o evento `RECONCILIATION_CLOSURE_EXPORT_GENERATED` estão especificados, mas o **formato** (PDF, CSV, XLSX) e o **conteúdo mínimo obrigatório** do relatório de fechamento (para auditoria externa, por exemplo) ainda não foram definidos.

## 11. O mesmo ator pode fechar e depois reabrir o mesmo fechamento?

Ver `FASE_6_RBAC.md` §3. Três alternativas propostas (nunca / sim com aviso / depende do papel). Qual é a política oficial?

## 12. Candidato `PENDING` não decidido bloqueia fechamento?

Ver `FASE_6_STATE_MACHINE.md` §3, código `CLOSURE_PENDING_CANDIDATES`. Hoje é apenas um `warning`. Deve virar `blocker`?

## 13. Existe tolerância de saldo não conciliado que ainda permite fechar?

Ver `CLOSURE_UNRECONCILED_BALANCE`. Se a pergunta 9 for respondida com um saldo de autoridade definido, ainda falta saber: qual valor (absoluto ou percentual) de diferença não conciliada é aceitável num fechamento sem intervenção manual?

## 14. Fechar uma sessão sem nenhum match (`OPEN` → `CLOSED` direto) é um caso legítimo?

Ver `CLOSURE_EMPTY_SESSION`. Um período realmente sem movimento bancário deveria poder ser "fechado como vazio" para efeito de registro histórico, ou isso é sempre um erro operacional que deve ser bloqueado?

---

## Como usar este documento

1. Levar as 14 perguntas ao financeiro/responsável de negócio antes de iniciar `6.3` no `FASE_6_IMPLEMENTATION_PLAN.md` (a etapa que implementa `ReconciliationClosureValidator`, onde as respostas viram código).
2. Registrar cada resposta como um ADR novo.
3. Atualizar `FASE_6_STATE_MACHINE.md` e `FASE_6_RBAC.md` para remover a marcação de "pendência" das regras respondidas, sem apagar o registro de que já foram discutidas como alternativas.
4. Perguntas sem resposta até o início da implementação usam o default seguro já documentado (política Governada, sem four-eyes, sem prazo, sem tolerância de saldo, sem escopo por conta além do que já existe) — nunca travam a implementação indefinidamente, mas também nunca inventam uma resposta definitiva no lugar do negócio.
