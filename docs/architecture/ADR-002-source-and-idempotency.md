# ADR-002 — Origem, identificador externo e idempotência

- Status: aceito
- Data: 2026-08-13

## Contexto

As integrações futuras precisam reenviar mensagens com segurança e dois sistemas podem usar o mesmo identificador local.

## Decisão

`source_systems` é uma tabela extensível, com código único, tipo, estado e configuração opcional. O título armazena `source_system_id`, `external_id`, `idempotency_key` e `payload_hash`.

O banco impõe unicidade para `(source_system_id, external_id)` e `(source_system_id, idempotency_key)`. Os identificadores têm 128 caracteres para manter os índices compostos compatíveis com o limite do MariaDB 10.1 usando `utf8mb4`.

Strings vazias são normalizadas para `NULL`, preservando a semântica dos índices únicos anuláveis do MariaDB. O `payload_hash` cobre o conteúdo financeiro normalizado e a quantidade de parcelas, mas não a própria `Idempotency-Key`, pois a chave identifica a requisição e não altera o evento financeiro.

O serviço de ingestão aplica a seguinte política:

1. chave nova: cria título, parcelas e auditoria na mesma transação;
2. mesma origem, chave e conteúdo: ignora e devolve o título existente;
3. mesmo `external_id` com conteúdo alterado: atualiza somente se ainda não houver liquidação;
4. mesma `Idempotency-Key` com conteúdo diferente: rejeita;
5. chaves que apontem para títulos diferentes: rejeita.

A primeira `Idempotency-Key` vinculada ao título permanece reservada e não é substituída em atualizações. O serviço bloqueia a linha da origem durante a decisão e as constraints únicas permanecem como última barreira contra concorrência. Liquidações externas usam a mesma dupla proteção por origem + `external_id` ou origem + chave idempotente.

## Consequências

- O mesmo `external_id` pode coexistir em origens diferentes.
- O índice único protege contra duplicidade mesmo se dois chamadores concorrentes ultrapassarem a verificação da aplicação.
- A futura API HTTP poderá repassar `Idempotency-Key` diretamente à camada de aplicação.
- Um registro genérico de todas as chaves HTTP, com retenção e replay de resposta, continua reservado para a fase da API; nesta fase a chave permanece associada ao recurso financeiro criado.
