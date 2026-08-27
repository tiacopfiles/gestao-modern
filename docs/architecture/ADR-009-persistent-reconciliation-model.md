# ADR-009 — Modelo de conciliação persistente

- Status: aceito
- Data: 2026-08-13

## Contexto

O núcleo moderno já registra títulos, parcelas e fatos bancários, mas não guardava a decisão humana de que um valor bancário corresponde a um ou mais títulos. Uma FK direta em `bank_transactions` não representa composição, rateio, conciliação parcial nem o histórico de uma decisão desfeita. A conciliação legada em `avt_conciliacoes` precisa continuar independente e intocada.

## Decisão

Uma `reconciliation_session` delimita uma conta e um período bancário. A combinação `(account_id, period_start, period_end)` é única. Nesta fase seus únicos estados operacionais são `OPEN` e `IN_REVIEW`; nenhum deles significa fechamento contábil.

Cada decisão forma um `reconciliation_match` persistente, pertencente à sessão. O método é explicitamente `MANUAL` e o estado é `CONFIRMED` ou `VOIDED`. O match registra usuário humano, instante e correlação. Desfazer muda o estado e acrescenta ator, data e motivo; não há `DELETE` no fluxo.

As duas tabelas de alocação ligam o match aos recursos:

- `reconciliation_match_titles`: título, parcela concreta e `allocated_amount`;
- `reconciliation_match_transactions`: fato bancário e `allocated_amount`.

O domínio aceita 1:1, 1:N, N:1 e N:N. Todo match confirmado precisa ter pelo menos uma alocação em cada lado e somas exatamente iguais em centavos. A mesma parcela e a mesma transação podem participar de matches diferentes até o limite disponível, possibilitando conciliação parcial. Uma única parcela pode ser resolvida automaticamente; títulos parcelados exigem parcela explícita.

As alocações confirmadas consomem disponibilidade. As alocações de matches `VOIDED` permanecem no histórico, mas deixam de consumir saldo. O serviço bloqueia em ordem estável a sessão, transações, títulos e parcelas com `lockForUpdate`, recalcula as somas já confirmadas dentro da transação e só então persiste o novo match.

## Por que não usar `financial_title_id` em `bank_transactions`

Uma coluna direta imporia, na prática, uma relação 1:1 ou N:1 sem representar valor alocado. Ela não conseguiria expressar um título pago em vários PIX, uma TED que paga vários títulos ou uma composição N:N. Também misturaria um fato bancário imutável com uma decisão humana revisável.

## Persistência e void

Refresh, logout e reinício do servidor não podem apagar a evidência da decisão. O match e suas alocações são registros de auditoria do processo. Por isso `void` não remove linhas: ele preserva quem confirmou, a composição original, quem desfez e por quê. Apenas o cálculo derivado de disponibilidade ignora o match desfeito.

## Integridade e compatibilidade

- Valores são `DECIMAL(15,2)` no banco e centavos inteiros no domínio.
- FKs novas existem apenas entre tabelas modernas e usam `RESTRICT` no delete.
- `account_id` e IDs de usuários não recebem FK contra estruturas legadas; a aplicação valida a conta e deriva o ator da sessão autenticada.
- A constraint única da sessão protege contra corrida, além da verificação de aplicação.
- Restrições de soma e disponibilidade são transacionais na aplicação porque MariaDB 10.1 não oferece uma constraint declarativa simples para agregados entre linhas.
- A concorrência ainda precisa de homologação simultânea real no MariaDB 10.1; SQLite valida regras, persistência e atomicidade, mas não comprova locks InnoDB.

## Consequências

- A conciliação nova cresce em paralelo a `avt_conciliacoes`.
- Não existe associação direta que mutile a imutabilidade do fato bancário.
- Decisões podem ser auditadas e desfeitas sem perda de história.
- Fechamento, reabertura, tolerâncias, tarifas, score e matching automático permanecem fora desta decisão.

