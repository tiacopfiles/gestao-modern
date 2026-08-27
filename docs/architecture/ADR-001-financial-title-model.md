# ADR-001 — Modelo canônico de título financeiro

- Status: aceito
- Data: 2026-08-13

## Contexto

O legado separa contas a pagar e a receber e mistura vencimento com realização financeira. Também usa `FLOAT(10,2)` e representa parcelamento por clonagem de registros.

## Decisão

`financial_titles` representa PAYABLE e RECEIVABLE no mesmo núcleo. A direção é explícita em `type`; regras particulares continuam podendo ser aplicadas por serviços. Valores novos usam `DECIMAL(15,2)` e a aplicação calcula em centavos inteiros.

Cada título possui uma ou mais linhas em `title_installments`, com identidade, número, vencimento, valor e estado próprios. A soma das parcelas é exata: a divisão usa centavos e o resíduo fica na última parcela. Datas preservam o dia âncora e tratam fim de mês explicitamente.

Liquidação é um fato separado em `title_settlements`. Assim, pagamentos e recebimentos parciais, múltiplos eventos e futuro estorno não sobrescrevem a identidade nem a obrigação original.

Um título parcelado deve informar a parcela em cada liquidação nesta fase. Isso evita que o título fique liquidado enquanto suas parcelas permaneçam abertas e mantém o saldo de cada parcela demonstrável. Títulos com uma única parcela resolvem essa associação automaticamente.

## Consequências

- O saldo é derivado do total menos liquidações confirmadas.
- Título e parcela têm estados derivados de seus eventos.
- Alterações em títulos que já possuam liquidação são rejeitadas na ingestão.
- Estorno está previsto no modelo, mas o fluxo de execução será concluído em fase posterior.
- Exclusões físicas são restringidas por FKs; o título usa exclusão lógica e fatos financeiros não são apagados pelos serviços.
