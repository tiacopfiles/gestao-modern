# ADR-014 — Snapshot canônico e hash do fechamento

- Status: aceito
- Data: 2026-08-14

## Contexto

Um fechamento precisa responder, no futuro, "o conteúdo é exatamente o que foi fechado?" mesmo que dados relacionados mudem depois (void tardio de um match anterior ao fechamento, nova versão do motor de matching, mudança de configuração). ADR-013 decide o modelo híbrido; este ADR decide como o hash é calculado.

## Decisão

`ReconciliationClosureHashService` calcula SHA-256 sobre uma representação canônica, seguindo o mesmo padrão já usado em `reconciliation_exceptions.signature_hash`/`reconciliation_candidates.signature_hash`:

1. `ReconciliationClosureSnapshotBuilder` monta uma estrutura associativa com: `schema_version`, `engine_version`, `account_id`, `reconciliation_session_id`, `period_start`/`period_end` (formato `Y-m-d`), `matches[]` (`match_id`, `status`, `total_amount`), `exceptions[]` (`exception_id`, `status`, `type`) e `metrics[]` (`metric_key`, `metric_value`) — nunca campos livres como nome de contraparte, apenas estrutura e valores financeiros/identificadores.
2. Valores monetários são strings decimais fixas de 2 casas (`Money::fromCents`), nunca `float`.
3. `ReconciliationClosureHashService::normalize()` ordena chaves de objeto alfabeticamente (`ksort` recursivo) e ordena listas de objetos pela própria representação JSON canônica de cada item — elimina qualquer dependência da ordem de inserção do chamador, sem exigir que quem monta o payload conheça previamente a chave primária de cada lista (o `SnapshotBuilder` também ordena por ID antes de persistir, por legibilidade do `snapshot_payload` armazenado, mas o hash não depende disso).
4. Serialização com `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)`.
5. `hash('sha256', $json)`.

`closure_hash` (`CHAR(64)`) e `hash_algorithm` (`string`, hoje sempre `sha256`) são persistidos junto de `snapshot_payload` (o próprio material hasheado, sem duplicação além dele).

### Campos deliberadamente fora do hash

`closed_by`, `closed_at`, `correlation_id` são metadados do **ato** de fechar, não do **conteúdo** fechado — já protegidos por `audit_events` e pelas colunas próprias de `reconciliation_closures`. Incluí-los no hash tornaria impossível verificar "o conteúdo financeiro é o mesmo" independentemente de quem/quando fechou.

### Versionamento

Qualquer mudança na lista de campos, normalização ou ordenação exige um novo `schema_version` (ex.: `closure-snapshot-v2`), nunca reinterpretação silenciosa da v1 — mesmo princípio já aplicado a `engine_version` em ADR-011. A versão hoje implementada é `closure-snapshot-v1`.

## Testado

- Determinismo: mesmos dados, dois cálculos independentes → hash idêntico.
- Insensibilidade à ordem de inserção das listas.
- Sensibilidade a mudança de conteúdo (`captured_status` alterado → hash diferente) e de versão (`schema_version` alterado, resto idêntico → hash diferente).

Ver `tests/Feature/ReconciliationClosureTest.php::test_hash_is_deterministic_order_independent_and_sensitive_to_content`.

## Consequências

- Um comando/rotina de auditoria futura pode recalcular o hash a partir do `snapshot_payload` armazenado e comparar com `closure_hash` — divergência é evidência de alteração indevida, que não deve ocorrer em operação normal (a linha nunca sofre `UPDATE` de conteúdo, ver ADR-013).
- Métricas cuja fórmula de negócio ainda não foi validada (`reconciliation_rate`, saldo inicial/final) não entram no payload nem no hash — nunca um valor inventado, ver ADR-015.
