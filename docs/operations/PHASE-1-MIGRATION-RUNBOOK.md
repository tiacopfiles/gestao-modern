# Runbook — migrations da Fase 1

## Objetivo

Aplicar somente o núcleo financeiro aditivo em uma instalação que já contém o schema legado `avt_*`. Este procedimento não migra histórico e não altera `avt_lancamentos`, `avt_recebimentos`, `avt_movimentos` ou `avt_conciliacoes`.

## Baseline aprovado

As migrations padrão abaixo não pertencem ao schema real e não podem estar no diretório de deploy:

- `0001_01_01_000000_create_users_table.php`;
- `0001_01_01_000001_create_cache_table.php`;
- `0001_01_01_000002_create_jobs_table.php`.

Elas estavam pendentes, nunca foram executadas no banco auditado e a primeira tentaria recriar `avt_users`. A solução é removê-las do artefato migrável, sem inserir registros artificiais em `avt_migrations`.

## Pré-condições

1. Confirmar PHP, aplicação, conexão, banco e `DB_PREFIX=avt_`.
2. Fazer backup verificável da aplicação e dump completo do banco.
3. Registrar contagens das quatro tabelas legadas.
4. Executar `php artisan migrate:status` e comparar cada linha com a lista aprovada abaixo.
5. Executar `php artisan migrate --pretend --force` e revisar o SQL.
6. Parar se aparecer qualquer `create`, `alter`, `drop`, `truncate`, `delete` ou `update` direcionado a tabelas legadas.

## Migrations aprovadas

- `2026_08_12_000001_create_documentos_modernos_table` — já executada no banco auditado;
- `2026_08_13_000010_create_source_systems_table`;
- `2026_08_13_000020_create_financial_titles_table`;
- `2026_08_13_000030_create_title_installments_table`;
- `2026_08_13_000040_create_title_settlements_table`;
- `2026_08_13_000050_create_audit_events_table`.

## Aplicação controlada

```bash
php artisan migrate --pretend --force
php artisan migrate --force
php artisan migrate:status
```

Depois, comparar novamente as contagens legadas e confirmar que as cinco tabelas novas estão vazias, exceto `avt_source_systems`, que deve conter as oito origens iniciais.

## Rollback

O `down()` foi validado em banco SQLite isolado e respeita a ordem das FKs. Em ambiente com dados, não executar rollback automaticamente: as tabelas novas contêm fatos financeiros e um `migrate:rollback` as removeria. Restaurar a aplicação e o banco a partir dos backups aprovados é a estratégia de recuperação do deploy.

## Evidência de validação local

Em 13/08/2026 foi executado, em arquivo SQLite temporário com `DB_PREFIX=avt_`: `migrate --pretend`, `migrate`, `migrate:status`, `migrate:rollback`, novo `migrate` e `migrate:status`. Todas as seis migrations terminaram como `Ran`; nenhuma tabela legada foi criada pelo conjunto da Fase 1.
