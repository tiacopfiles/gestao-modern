# ADR-012 — Fila persistente de divergências

- Status: aceito
- Data: 2026-08-13

## Decisão

Uma divergência é uma situação operacional que o motor não pode resolver com segurança. Ela é persistida em `reconciliation_exceptions`, separada de candidatos, matches, títulos, liquidações e fatos bancários. Tipos iniciais: `NO_CANDIDATE`, `AMBIGUOUS_CANDIDATES`, `AMOUNT_MISMATCH`, `STRONG_IDENTIFIER_CONFLICT`, `PARTIALLY_RECONCILED_REMAINDER` e `MISSING_REQUIRED_DATA`.

Estados:

- `OPEN`: gerada e pendente;
- `IN_REVIEW`: reservada para fluxo de análise futuro;
- `JUSTIFIED`: operador registrou ator, data e motivo, sem criar fato financeiro;
- `RESOLVED`: um candidato relacionado foi aceito e resultou em match válido.

Regenerar atualiza uma exceção aberta de mesma assinatura, mas nunca apaga histórico nem reabre `JUSTIFIED`/`RESOLVED`. A assinatura inclui versão, tipo e recursos relacionados. Evidências guardam somente fatos estruturados mínimos e explicação segura.

## Regras confirmadas

- ambiguidade não escolhe vencedor;
- diferença de valor não cria ajuste;
- “sem candidato” não cria movimento;
- justificativa exige razão e ator autenticado;
- resolução por aceite ocorre apenas depois da confirmação pelo serviço manual;
- toda transição relevante produz `audit_events` com `correlation_id`;
- divergência nunca altera título, parcela, transação, settlement ou legado.

## Configurações técnicas provisórias

A forma de detectar ambiguidade, a janela temporal, os limites do pool e os sinais usados para detectar diferença são provisórios e versionados conforme ADR-011. SLA, atribuição, severidade e transição operacional para `IN_REVIEW` ainda dependem de regra de negócio.

## Consequência

O sistema conserva o problema em vez de ocultá-lo ou “corrigi-lo” financeiramente. A fila apoia investigação humana auditável e pode ser desligada com `RECONCILIATION_MATCHING_ENABLED=false`, sem desligar a conciliação manual.
