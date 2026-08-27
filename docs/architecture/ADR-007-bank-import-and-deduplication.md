# ADR-007 — Lotes bancários e camadas de deduplicação

- Status: aceito
- Data: 2026-08-13

## Contexto

Uma importação precisa responder quem enviou, de qual origem e conta veio, quais linhas entraram, quais foram repetidas ou rejeitadas e qual falha ocorreu. Idempotência HTTP, arquivo repetido e transação repetida são problemas diferentes e não podem depender de uma única heurística.

## Decisão

Toda entrada bancária cria ou referencia um `import_batch`. A API canônica cria um lote `API/CANONICAL_API` de um item. OFX cria lote `FILE/OFX`, com estados `RECEIVED`, `PROCESSING`, `COMPLETED`, `PARTIAL` ou `FAILED`. `import_batch_items` preserva posição, identidade, resultado (`IMPORTED`, `DUPLICATE`, `REJECTED`), erro seguro e vínculo opcional com a transação.

A deduplicação possui três camadas independentes:

1. `Idempotency-Key`: inbox por credencial, método, path e hash canônico. JSON mantém o contrato da Fase 2; multipart inclui campos e SHA-256 dos bytes, nunca nome ou MIME fornecidos pelo cliente.
2. Hash do arquivo: SHA-256 identifica o mesmo conteúdo para a mesma origem e conta. Um lote não falho existente é devolvido, inclusive com outra chave HTTP. O arquivo bruto não é persistido.
3. Identidade da transação: constraint e serviço usam conta + origem + `external_id`/`FITID`. Arquivos parcialmente sobrepostos compartilham somente os fatos com a mesma identidade forte.

A mesma identidade e mesmo payload normalizado resulta em `DUPLICATE`. A mesma identidade com conteúdo diferente resulta em conflito, nunca update. IDs diferentes preservam linhas legítimas ainda que valor, direção e data coincidam. Linhas sem identidade forte são `REJECTED`; não se cria identidade heurística.

Falha estrutural marca o lote como `FAILED`. Falha isolada de uma linha é persistida e permite `PARTIAL`. Um lote `FAILED` não bloqueia nova tentativa do mesmo arquivo. O processamento OFX usa uma transação curta para a inbox, uma transação por fato/item e atualização final do lote; isso evita manter toda a leitura sob uma transação SQL longa.

## Consequências

- replays HTTP preservam a resposta, e arquivos repetidos não recriam lote útil nem transações;
- sobreposição de períodos é segura quando o banco fornece FITID estável;
- falhas parciais são diagnosticáveis sem armazenar dados bancários completos no item;
- a constraint continua sendo a última defesa contra corrida;
- SQLite valida regras e constraints, mas concorrência/locks exigem homologação em MariaDB 10.1 antes de produção;
- retenção de hashes, lotes e itens ainda precisa de política operacional futura.
