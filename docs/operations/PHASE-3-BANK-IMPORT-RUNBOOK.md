# Runbook — Transações bancárias e importação OFX (Fase 3)

## Objetivo e limite de segurança

Operar as três migrations aditivas, a API bancária canônica e o importador OFX. Este runbook não autoriza modificar os produtores `G:\xampp\htdocs\contas` e `G:\xampp\htdocs\contasareceber`, migrar banco real automaticamente, conciliar títulos ou alterar `avt_lancamentos`, `avt_recebimentos`, `avt_movimentos` e `avt_conciliacoes`.

## Pré-requisitos

1. Ter backup restaurável, janela aprovada e contagens/checksums das tabelas protegidas.
2. Confirmar PHP/Laravel, MariaDB 10.1, conexão e `DB_PREFIX=avt_` no ambiente-alvo.
3. Definir `BANK_IMPORT_OFX_MAX_BYTES` (padrão `5242880`, 5 MiB).
4. Rodar testes, Pint, `composer validate`, `composer audit`, status e SQL pretendido.
5. Parar se o SQL mencionar uma tabela legada protegida ou se o banco não for o ambiente esperado.

## Migrations da Fase 3

- `2026_08_13_000100_create_import_batches_table`;
- `2026_08_13_000110_create_bank_transactions_table`;
- `2026_08_13_000120_create_import_batch_items_table`.

Aplicação controlada, nunca automática em produção:

```bash
php artisan migrate:status
php artisan migrate --pretend --force
php artisan migrate --force
php artisan migrate:status
```

As estruturas são aditivas. `account_id` não possui FK para `contas`; a aplicação valida a existência da conta. As demais FKs alcançam somente tabelas modernas.

## Credencial e scopes mínimos

Exemplo de homologação, sem token real na documentação:

```bash
php artisan integration-client:issue BANK_IMPORT "Importador OFX homologação" \
  --scope=bank-transactions:read --scope=bank-transactions:write \
  --scope=bank-imports:read --scope=bank-imports:write
```

Guarde o token somente em canal secreto. Use apenas scopes necessários e revogue com `php artisan integration-client:revoke ID`.

## Exemplos de operação

Variáveis ilustrativas:

```bash
BASE_URL="https://homologacao.example/api/v1"
TOKEN="<TOKEN_OPACO>"
```

Enviar uma transação canônica:

```bash
curl -sS -X POST "$BASE_URL/bank-transactions" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: bank-tx-20260813-001" \
  -H "X-Correlation-ID: 3b37498d-f292-4bd9-81a0-65da65eefb3d" \
  --data '{"account_id":12,"external_id":"TX-001","transaction_date":"2026-08-13","direction":"CREDIT","amount":"1250.00","currency":"BRL","description":"PIX RECEBIDO"}'
```

Consultar a mesma identidade:

```bash
curl -sS "$BASE_URL/bank-transactions/12/TX-001" \
  -H "Authorization: Bearer $TOKEN"
```

Enviar OFX:

```bash
curl -sS -X POST "$BASE_URL/bank-imports/ofx" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: ofx-20260813-conta12" \
  -F "account_id=12" -F "file=@statement.ofx"
```

Consultar lote e itens:

```bash
curl -sS "$BASE_URL/bank-imports/321" -H "Authorization: Bearer $TOKEN"
curl -sS "$BASE_URL/bank-imports/321/items?page=1" -H "Authorization: Bearer $TOKEN"
```

Para replay, repita método, URL, campos e bytes com a mesma chave. Reutilizar a chave com conteúdo diferente retorna `409 IDEMPOTENCY_KEY_REUSED`. O mesmo arquivo com outra chave retorna o lote existente (`FILE_DUPLICATE`); arquivos sobrepostos importam somente FITIDs ainda ausentes.

## Estados, duplicidade e falhas

- `RECEIVED`/`PROCESSING`: recebido ou em processamento;
- `COMPLETED`: nenhuma linha rejeitada; pode conter duplicadas;
- `PARTIAL`: pelo menos uma linha válida e outra rejeitada;
- `FAILED`: arquivo estruturalmente inválido ou todas as linhas rejeitadas.

Diagnóstico comum:

- `BANK_ACCOUNT_NOT_FOUND`: corrigir `account_id`; conta não é criada pelo OFX;
- `BANK_IMPORT_INVALID_FILE`: validar estrutura, encoding e ausência de construções XML externas;
- `BANK_IMPORT_UNSUPPORTED_FORMAT`: enviar `.ofx` real, não confiar só na extensão;
- `BANK_IMPORT_TOO_LARGE`: revisar o arquivo e o limite, sem aumentá-lo sem avaliação;
- `BANK_TRANSACTION_ID_CONFLICT`: mesma conta/origem/ID com conteúdo divergente; investigar o produtor;
- item `BANK_TRANSACTION_ID_REQUIRED`: linha sem FITID, mantida como rejeitada para diagnóstico.

## Logs e auditoria

Busque `integration_api_request` e `bank_import_processed` por `correlation_id`, depois cruze `integration_requests`, `import_batches`, `import_batch_items`, `bank_transactions` e `audit_events`. Os eventos incluem início/fim/falha de lote e importação/duplicidade de transação. Não solicite token, chave idempotente completa, arquivo OFX bruto ou descrição bancária integral em ticket/log.

## Rollback e recuperação

Após receber fatos, não use rollback de migration: ele apagaria lotes e transações. Para interromper entrada, revogue/desative a credencial e reverta a aplicação pelo procedimento aprovado. Recuperação de banco deve usar backup validado. Em banco isolado descartável, as três migrations possuem `down` na ordem inversa.

## Homologação obrigatória antes de produção

SQLite não prova locks nem compatibilidade operacional. Em MariaDB 10.1 dedicado, validar migrations/rollback, índices, upload limite e duas chamadas concorrentes: mesma chave, mesmo arquivo com chaves distintas e arquivos sobrepostos. Confirmar uma identidade por conta/origem/FITID, respostas não-500 e invariância das tabelas legadas protegidas.
