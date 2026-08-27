# ADR-004 — Autenticação M2M e autorização por escopos

- Status: aceito
- Data: 2026-08-13

## Contexto

A API externa não pode reutilizar a sessão web nem representar uma máquina como usuário humano. Cada chamada precisa identificar a credencial e a origem real, permitir rotação/revogação e limitar Payables e Receivables separadamente. O banco-alvo é MariaDB 10.1 e não há motivo para adicionar uma dependência apenas para emissão de tokens opacos.

## Decisão

Cada credencial é uma linha de `integration_clients`, vinculada obrigatoriamente a um `source_system`. O token emitido tem prefixo `acop_` e 256 bits aleatórios gerados por `random_bytes`. O segredo aparece apenas na saída do comando de emissão; o banco guarda SHA-256, prefixo não secreto, escopos, estado, expiração opcional e último uso.

O middleware calcula o hash do Bearer token, busca a credencial por igualdade, verifica credencial, expiração e origem ativas e injeta o cliente autenticado na requisição. `source_system`, tipo do título e `external_id` da atualização nunca são aceitos como autoridade do corpo: vêm, respectivamente, da credencial, da rota e do path.

Os escopos v1 são `payables:read`, `payables:write`, `receivables:read` e `receivables:write`. A emissão e a revogação são feitas por comandos Artisan, favorecendo uma operação auditável e sem UI administrativa prematura. `actor_id` continua nulo em ações M2M; `integration_client_id` foi adicionado de forma nullable a `audit_events`.

## Consequências

- múltiplas credenciais podem representar a mesma origem e ser rotacionadas individualmente;
- o token bruto não pode ser recuperado; perda exige emissão de uma nova credencial;
- revogação e desativação da origem têm efeito na próxima chamada;
- não foi adicionada biblioteca de autenticação;
- TLS continua sendo requisito da infraestrutura de produção, pois Bearer token protege posse, não transporte.
