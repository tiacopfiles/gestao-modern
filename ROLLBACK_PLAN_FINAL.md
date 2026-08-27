# Plano de rollback — final (Fases 1–6)

Princípio geral: **feature flags OFF é sempre a primeira resposta**, nunca uma operação destrutiva. Rollback destrutivo (drop de dados, restauração de backup) é o último recurso, usado apenas quando flags OFF não resolve o problema (ex.: bug já persistiu dado incorreto antes de ser detectado).

## Nível 1 — Kill switch (primeira resposta, sempre)

```dotenv
RECONCILIATION_CLOSING_ENABLED=false   # desliga só fechamento/reabertura
RECONCILIATION_MATCHING_ENABLED=false  # desliga só motor/sugestões/fila
RECONCILIATION_V2_ENABLED=false        # desliga toda a v2 (inclui matching e closing)
```

Reversível em segundos (mudar `.env` + `php artisan config:cache` se config estiver cacheada). Não apaga nenhum dado. Preserva conciliação manual, `/conciliacoes`, Contas a Pagar/Receber e API v1 intactos em qualquer combinação. Este nível resolve a grande maioria dos cenários de incidente sem tocar em banco.

## Nível 2 — Rollback de código (aplicação)

Se o bug está no código da aplicação (não no dado já persistido):

1. Reverter para a versão anterior da aplicação (deploy do artefato/tag anterior), mantendo o histórico de commits — nunca `git reset --hard` sobre trabalho compartilhado.
2. Manter as flags OFF (nível 1) até confirmar que a versão revertida está estável.
3. As 26 migrations desta aplicação são **aditivas** (`CREATE TABLE`) — uma versão anterior do código continua funcionando com o schema mais novo aplicado (tabelas extras não usadas não quebram nada). **Não é necessário reverter migrations para reverter código.**

## Nível 3 — Rollback de migration (somente quando comprovadamente seguro)

Cada migration das Fases 1–6 tem `down()` completo e testado (`migrate:rollback`/`migrate:reset` fazem parte da suíte de homologação MariaDB — ver `HOMOLOGACAO_MARIADB_FINAL.md`). Ainda assim:

- **Nunca** rodar `migrate:rollback`/`migrate:fresh`/`migrate:reset`/`db:wipe` em banco com dados reais gravados nas tabelas modernas (`financial_titles`, `bank_transactions`, `reconciliation_*`) — o `down()` faz `DROP TABLE`, que apaga esses dados permanentemente.
- Rollback de migration só é seguro em um destes casos: (a) banco recém-provisionado, ainda sem uso real; (b) ambiente de homologação/teste descartável; (c) after um backup completo e restauração testada, com aprovação explícita do responsável nominal.
- Se dados reais já existem e uma migration precisa ser desfeita, o caminho correto é uma **nova migration corretiva** (aditiva ou com `ALTER` cuidadoso), nunca o `down()` da migration original.

## Nível 4 — Restauração de backup (último recurso)

Usado apenas quando um dado incorreto já foi persistido e não pode ser corrigido por uma correção pontual (ex.: script de correção nos dados afetados). Pré-requisitos:

- Backup íntegro e testado (ver `PRE_PRODUCTION_READINESS_FINAL.md` item 2).
- Janela de manutenção comunicada.
- Aprovação explícita do responsável nominal.
- Registro de exatamente quais dados serão perdidos (tudo criado entre o backup e a restauração).

## Reverter usuários para o legado

Como o legado (`/conciliacoes`, Contas a Pagar/Receber) nunca é desativado durante a operação paralela (ver `PLANO_OPERACAO_PARALELA.md`), "reverter para o legado" é simplesmente **parar de direcionar usuários para `/reconciliacao-v2`** — não existe migração de dados a desfazer, porque os dois sistemas operam sobre bases de dados conceitualmente separadas (o moderno nunca escreve nas tabelas `avt_*`). Basta remover o(s) usuário(s) de `RECONCILIATION_V2_MANAGE_USER_IDS`/`VIEW_USER_IDS` ou desligar a flag `V2_ENABLED` — o trabalho que a equipe já fez no legado continua exatamente onde estava.

## Preservação dos dados modernos durante qualquer rollback

Mesmo nos níveis 1–2 (os únicos esperados em operação normal), os dados já persistidos em `reconciliation_matches`, `reconciliation_closures`, etc. **nunca são apagados automaticamente** — desligar uma flag apenas impede novas escritas via UI/API daquela funcionalidade; o histórico já criado continua consultável assim que a flag for religada. Isso é uma propriedade estrutural do design (ADR-009, ADR-013): void/reopen nunca fazem `DELETE`.

## Checklist rápido de decisão

```text
Bug de comportamento, sem dado incorreto persistido?        → Nível 1 (flag OFF)
Bug de código confirmado, sem dado incorreto persistido?     → Nível 1 + Nível 2
Dado incorreto persistido, corrigível por script pontual?    → Nível 1 + correção pontual (nova migration/comando, nunca UPDATE manual sem plano)
Dado incorreto persistido, não corrigível pontualmente?       → Nível 1 + Nível 4 (backup), com aprovação
Schema com problema estrutural em ambiente ainda sem uso real? → Nível 3, com backup mesmo assim
```
