# Runbook — API de integração financeira v1

## Objetivo e limite de segurança

Operar a API v1 e aplicar somente as quatro migrations aditivas da Fase 2. Este procedimento não modifica produtores externos, não migra histórico e não altera `avt_lancamentos`, `avt_recebimentos`, `avt_movimentos` ou `avt_conciliacoes`.

## Pré-condições de deploy

1. Confirmar backup restaurável da aplicação e dump integral do banco.
2. Registrar contagens e checksums/indicadores das quatro tabelas legadas.
3. Confirmar PHP, Laravel, conexão, MariaDB 10.1 e `DB_PREFIX=avt_`.
4. Executar `php artisan test`, `vendor/bin/pint --test`, `composer validate` e `composer audit` no artefato.
5. Executar `php artisan migrate:status` e revisar a lista pendente.
6. Executar `php artisan migrate --pretend --force`; parar se houver DDL/DML contra tabela legada.

## Migrations aprovadas da Fase 2

- `2026_08_13_000060_create_integration_clients_table`;
- `2026_08_13_000070_create_integration_requests_table`;
- `2026_08_13_000080_create_title_cancellations_table`;
- `2026_08_13_000090_add_integration_client_id_to_audit_events_table`.

O último item altera somente a tabela moderna `audit_events`, acrescentando FK nullable. Nenhuma migration da Fase 1 foi reescrita.

## Aplicação controlada

```bash
php artisan migrate --pretend --force
php artisan migrate --force
php artisan migrate:status
php artisan route:list --path=api/v1
```

Depois, confirmar as quatro novas estruturas, comparar novamente as contagens legadas e executar smoke tests com uma origem de homologação. Não executar migrations diretamente no banco real sem backup, revisão do SQL e janela aprovada.

## Emissão e rotação de credenciais

```bash
php artisan integration-client:issue AGROCOLITTI "AgroColitti homologação" \
  --scope=payables:read --scope=payables:write --expires="2026-12-31T23:59:59-03:00"
```

Copiar o token por canal secreto no momento da emissão; ele não poderá ser consultado novamente. Não colocar token em shell history compartilhado, ticket, log ou documentação. Para rotação, emitir a nova credencial, trocar no produtor, validar tráfego e revogar a anterior:

```bash
php artisan integration-client:revoke 123
```

## Observabilidade e diagnóstico

Pesquisar o log `integration_api_request` por `correlation_id`, `integration_client_id`, rota e status. Cruzar a correlação com `integration_requests`, `financial_titles`, `title_cancellations` e `audit_events`. Logs não possuem token, chave idempotente integral nem payload financeiro.

Estados da inbox:

- `COMPLETED`: resposta determinística disponível para replay;
- `FAILED`: falha 5xx; o produtor pode repetir exatamente a chamada com a mesma chave;
- `PROCESSING`: chamada ativa ou registro interrompido; não alterar manualmente sem investigar transação, logs e correlação.

## Incidentes

- `401`: verificar credencial ativa, expiração e origem ativa, sem pedir o token completo em canal aberto.
- `403`: comparar endpoint com os escopos concedidos.
- `409 IDEMPOTENCY_KEY_REUSED`: o produtor reutilizou a chave com método, path ou corpo diferente; usar nova chave somente para um novo evento lógico.
- `409 IDEMPOTENCY_REQUEST_IN_PROGRESS`: aguardar e repetir o mesmo request; investigar se permanecer.
- `429`: respeitar `Retry-After`; o default é 60 requisições/minuto por credencial e pode ser ajustado por `INTEGRATION_API_RATE_LIMIT`.
- `5xx`: repetir a mesma chamada e chave; a resposta 5xx não é replay permanente.

## Rollback

Não executar rollback destrutivo após a API receber dados. Desativar/revogar credenciais interrompe novas entradas de forma reversível. Se o deploy precisar ser revertido, restaurar aplicação e banco conforme o plano aprovado. `migrate:rollback` removeria fatos, inbox e credenciais e só é aceitável em banco isolado descartável.

## Homologação de concorrência

SQLite não reproduz os locks do MariaDB. Antes de produção, disparar duas chamadas paralelas idênticas contra homologação MariaDB 10.1 e confirmar: um título, uma auditoria de criação, uma linha de inbox e replay lógico na segunda resposta.
