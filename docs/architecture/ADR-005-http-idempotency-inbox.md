# ADR-005 — Inbox transacional de idempotência HTTP

- Status: aceito
- Data: 2026-08-13

## Contexto

A idempotência de domínio da Fase 1 protege a identidade do título, mas não preserva a resposta HTTP nem distingue método e rota. Um produtor pode sofrer timeout e repetir a mesma chamada sem saber se o primeiro processamento terminou.

## Decisão

Toda mutação v1 exige `Idempotency-Key`. A chave bruta não é persistida: `integration_requests` guarda SHA-256 e um prefixo curto para suporte. O hash da requisição cobre método, path real e JSON com chaves ordenadas recursivamente; exclui Authorization, Idempotency-Key, correlação e demais headers.

O escopo da chave é a credencial (`integration_client_id`). Uma constraint única em cliente + hash da chave e um lock pessimista na linha do cliente serializam decisões concorrentes. A transação externa inclui registro `PROCESSING`, controller, serviço financeiro, auditoria e gravação da resposta `COMPLETED`. Assim, uma segunda chamada espera o lock e observa a resposta concluída; se existir um `PROCESSING` persistente, recebe `409 IDEMPOTENCY_REQUEST_IN_PROGRESS`.

Com a mesma chave e o mesmo hash, status e corpo JSON armazenados são reproduzidos, com `Idempotency-Replayed: true` e apenas o metadado de replay alterado. Mesma chave com método, path ou payload diferente retorna `409 IDEMPOTENCY_KEY_REUSED`.

Falhas 5xx não são armazenadas como resposta definitiva. A transação financeira é revertida, a inbox fica `FAILED` sem corpo reproduzível e a mesma chave + mesmo hash pode iniciar outra tentativa. Erros determinísticos 4xx ocorridos depois da validação idempotente são `COMPLETED` e reproduzidos.

## Consequências

- uma única credencial serializa suas mutações durante a curta transação, uma escolha conservadora de correção para esta fase;
- credenciais diferentes podem reutilizar a mesma string de chave;
- a proteção final continua sendo composta pelas constraints da inbox e de `financial_titles`;
- SQLite valida contrato, transações e constraints, mas não prova o comportamento de locks do MariaDB; um teste concorrente real deve integrar a homologação MariaDB 10.1;
- retenção/expurgo da inbox não foi automatizada nesta fase e exige política antes de crescer em produção.
