# Pre-production readiness — plano futuro, não executado

Status atual: **não autorizado**. A Fase 5.5 está `NO-GO / BLOCKED` por falta da homologação MariaDB 10.1 descartável. Este documento é somente um checklist conservador para um futuro em que o blocker tenha sido removido.

## Gate de entrada

- Fase 5.5 classificada formalmente como `GO PARA FASE 6` com evidências MariaDB 10.1;
- Fase 6 implementada, revisada e homologada separadamente;
- aprovação explícita dos responsáveis técnico, negócio, infraestrutura e segurança;
- nenhuma pendência crítica de centavos, idempotência, concorrência, autorização ou legado.

## Checklist obrigatório

1. Definir responsável nominal pela implantação e canal de decisão.
2. Confirmar backup íntegro, testado e com procedimento de restauração cronometrado.
3. Inspecionar o schema real somente em janela/autorização apropriada, inclusive versão, engine, charset, collation, índices, FKs e nomes conflitantes.
4. Executar `migrate --pretend` no ambiente controlado e revisar todo SQL; não usar esse comando como única prova.
5. Definir janela de implantação, congelamento de alterações e comunicação aos usuários.
6. Publicar código com `RECONCILIATION_V2_ENABLED=false` e `RECONCILIATION_MATCHING_ENABLED=false`.
7. Aplicar migrations controladas, com monitoramento e critério de abortar antes de qualquer passo irreversível.
8. Executar smoke tests de autenticação, API V1, núcleo financeiro, importação, legado e flags OFF.
9. Confirmar observabilidade: logs correlacionados, erros, latência, deadlocks, filas e métricas de banco.
10. Manter plano de rollback de código e plano de recuperação do banco; migrations com dados exigem estratégia própria e validação prévia.
11. Habilitar a V2 manual apenas para grupo limitado e autorizado.
12. Validar operação, auditoria, segregação e ausência de efeitos no legado.
13. Habilitar matching assistido apenas para grupo limitado; nunca auto-match por score.
14. Reavaliar antes de ampliar público ou volume.

## Estratégia de ativação futura

```text
deploy de código
→ flags OFF
→ migrations controladas
→ smoke tests e observabilidade
→ V2 manual para grupo limitado
→ validação operacional
→ matching assistido para grupo limitado
```

Não desativar `/conciliacoes` nem Contas a Pagar/Receber. A coexistência é obrigatória até decisão formal posterior.

## Critérios de aborto

Abortar e acionar o plano de recuperação se houver:

- versão/schema divergente do homologado;
- SQL inesperado ou lock prolongado;
- erro de migration ou constraint;
- perda/arredondamento de centavos;
- duplicação, over-allocation ou double match;
- regressão na API V1 ou no legado;
- flag ineficaz ou falha crítica de autorização;
- escrita indevida em tabela legada;
- observabilidade insuficiente para decidir com segurança.

## Evidências e responsabilidades

Guardar aprovações, hashes dos artefatos, backup, saída sanitizada de migrations/smoke tests, métricas, horários, responsáveis e decisão final. Nenhuma senha, token, dado financeiro real ou dump deve entrar no pacote documental.
