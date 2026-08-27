# Runbook — Matching e divergências (Fase 5)

## Escopo e segurança

Este runbook opera somente `gestao-modern`. Não autoriza acessar `G:\xampp\htdocs\contas` ou `G:\xampp\htdocs\contasareceber`, alterar tabelas legadas protegidas, aplicar migrations em produção, dar baixa, confirmar automaticamente, ajustar valores, lançar tarifas ou fechar período.

## Flags e permissões

Padrão seguro:

```dotenv
RECONCILIATION_V2_ENABLED=false
RECONCILIATION_MATCHING_ENABLED=false
RECONCILIATION_V2_VIEW_USER_IDS=
RECONCILIATION_V2_MANAGE_USER_IDS=
```

`VIEW` permite ver candidatos, evidências e divergências. `MANAGE` permite gerar, aceitar, rejeitar e justificar. Ator sempre vem da sessão autenticada. Com v2 ligada e matching desligado, o workspace manual continua funcionando e as seis rotas de matching retornam 404.

## Pré-requisitos e configuração

1. Backup e janela aprovados; ambiente e prefixo confirmados.
2. Revisar SQL de `migrate --pretend`; abortar se houver referência às quatro tabelas protegidas.
3. Homologar as migrations 000170–000200 e locks em MariaDB descartável.
4. Revisar pesos, bands, janela, pools e composição em `config/reconciliation_matching.php`; são provisórios.
5. Manter as duas flags desligadas durante migration e smoke test.

## Operação

- **Gerar:** na sessão v2, use “Gerar sugestões e divergências”. Isso somente persiste hipóteses/filas.
- **Inspecionar:** abra candidato para composição, score, banda, versão e impactos dos sinais.
- **Aceitar:** exige ação humana. O serviço trava candidato, chama `ManualReconciliationService`, revalida conta/período/direção/moeda/disponibilidade/totais e só então marca `ACCEPTED`. Falha torna o candidato `STALE`.
- **Rejeitar:** informe motivo obrigatório. O candidato e evidências permanecem.
- **Divergências:** filtre por status/tipo/recurso; abra o detalhe para contexto mínimo.
- **Justificar:** informe motivo obrigatório. Isso não cria match nem altera fatos financeiros.

## Auditoria e observabilidade

Eventos principais: `RECONCILIATION_MATCHING_GENERATED`, `RECONCILIATION_CANDIDATE_ACCEPTED`, `RECONCILIATION_CANDIDATE_REJECTED`, `RECONCILIATION_CANDIDATE_STALE` e `RECONCILIATION_EXCEPTION_JUSTIFIED`, além do `RECONCILIATION_MATCH_CONFIRMED` da Fase 4. Correlacione por usuário, sessão, candidato/match e `correlation_id`. Logs não contêm documentos nem descrições completos.

## Troubleshooting

- 404 em matching: confira ambas as flags e cache de configuração;
- 403: confira allowlist `VIEW`/`MANAGE`;
- candidato `STALE`: outro match consumiu saldo ou o recurso deixou de ser válido; gere novamente;
- muitos candidatos: reduza pool, limite por recurso, janela ou composição e versionar a regra;
- nenhuma sugestão: confirme conta, período, direção, moeda, data e disponibilidade; consulte divergências;
- deadlock/timeout: desligue somente matching, preserve correlações e investigue índices/locks antes de repetir.

## Kill switch e rollback

Primeira resposta: `RECONCILIATION_MATCHING_ENABLED=false` e reconstrução controlada do cache. Isso desliga motor/sugestões/fila automática, mas preserva conciliação manual. Para desligar toda v2, use `RECONCILIATION_V2_ENABLED=false`. Nenhuma opção afeta API v1, importação bancária, módulos de contas ou `/conciliacoes`.

Não faça rollback de tabelas com dados. Reverta a versão da aplicação mantendo histórico. `down()` é apenas para banco isolado/descartável e apaga estruturas modernas da Fase 5 em ordem inversa.
