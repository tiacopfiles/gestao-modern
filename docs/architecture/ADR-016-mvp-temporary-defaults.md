# ADR-016 — Defaults temporários de MVP para homologação funcional

- Status: **aceito como provisório** — vale para desenvolvimento e homologação local, **não** para produção
- Data: 17/08/2026
- Substitui: nada. Complementa ADR-015, que já documentava os mesmos defaults como "seguros provisórios"

## Contexto

As 14 perguntas de negócio da Fase 6 (`docs/phase-6-design/FASE_6_PERGUNTAS_NEGOCIO.md`)
seguem sem resposta formal do financeiro. Enquanto elas estavam abertas, o
projeto não conseguia sair do estado "desenvolvimento avançado": faltava um
ambiente onde o fluxo inteiro pudesse ser percorrido e demonstrado.

A decisão de projeto foi **destravar a conclusão funcional** congelando
temporariamente as políticas de governança nos defaults já implementados, em vez
de esperar as respostas. Produção continua bloqueada.

## Decisão

Ficam congelados, **como defaults temporários de MVP/homologação**, os seguintes
comportamentos — todos já implementados, nenhum inventado agora:

| Tema | Default congelado | Pergunta de negócio |
|---|---|---|
| Granularidade do fechamento | Por conta bancária + período | 8 |
| Quem fecha | Usuário na allowlist fecha sozinho | 1 |
| Segunda aprovação (four-eyes) | **Não exigida** | 5 |
| Quem reabre | Usuário na allowlist de reabertura | 2 |
| Mesmo ator fecha e reabre | **Permitido**, sem aviso especial | 11 |
| Prazo para fechar o período | **Sem prazo** | 6 |
| Janela máxima para reabertura | **Sem limite temporal** | 7 |
| Divergência aberta / em revisão | **Bloqueia** o fechamento (política Governada) | 3 |
| Divergência justificada | **Não bloqueia** | 4 |
| Sugestão automática pendente | Apenas aviso, não bloqueia | 12 |
| Fechamento sem nenhum match | Permitido, com aviso | 14 |
| Saldo inicial/final | **Fora de escopo** — só totais de crédito/débito | 9 |
| Tolerância de saldo não conciliado | **Fora de escopo** (depende da 9) | 13 |
| Exportação de relatório (PDF/CSV/XLSX) | **Fora de escopo** — não é requisito para o sistema ser considerado funcional | 10 |

## Consequências

**O que isto autoriza:** operar e demonstrar o sistema ponta a ponta em ambiente
local/homologação, com dados sintéticos, incluindo fechamento e reabertura reais.

**O que isto explicitamente NÃO autoriza:**

- não é política definitiva de produção;
- não substitui a resposta do financeiro a nenhuma das 14 perguntas;
- não autoriza ligar `RECONCILIATION_CLOSING_ENABLED=true` fora de
  desenvolvimento/homologação;
- não autoriza deploy, migration real ou qualquer acesso a produção.

**Reversibilidade:** todos os itens acima são regras de validação e permissão
sobre estruturas que já existem. Cada resposta futura do financeiro vira um ADR
próprio (`ADR-01X-reconciliation-closure-policy-<tema>.md`) e ajusta
`ReconciliationClosureValidator`, `ReconciliationReopeningService` ou os gates —
sem migration nova e sem reescrita de modelo. O único item que exige construção
do zero é a exportação (pergunta 10).

**Risco aceito conscientemente:** ausência de four-eyes e a permissão para o
mesmo ator fechar e reabrir são controles internos que um auditor pode exigir.
Estão registrados aqui para que a decisão seja visível, não implícita.

## Configuração correspondente

Em desenvolvimento/homologação, o `.env` liga o módulo e autoriza o usuário demo:

```dotenv
RECONCILIATION_V2_ENABLED=true
RECONCILIATION_MATCHING_ENABLED=true
RECONCILIATION_CLOSING_ENABLED=true
RECONCILIATION_V2_VIEW_USER_IDS=1
RECONCILIATION_V2_MANAGE_USER_IDS=1
RECONCILIATION_CLOSE_USER_IDS=1
RECONCILIATION_REOPEN_USER_IDS=1
```

Em `.env.example` — o que vale para produção — todas nascem desligadas e as
allowlists vazias. Esse contraste é intencional e não deve ser "corrigido".
