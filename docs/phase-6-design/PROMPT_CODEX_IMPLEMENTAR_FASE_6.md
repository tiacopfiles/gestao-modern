PRÉ-CONDIÇÃO:

Somente executar este prompt se
PACOTE_CONTINUACAO_FASE_5_5C_GO.md
existir e declarar GO PARA FASE 6.

Se esse arquivo não existir, ou existir mas declarar NO-GO/BLOCKED, pare imediatamente e não altere nenhum código. Informe ao usuário que a pré-condição não foi satisfeita.

---

# MISSÃO — IMPLEMENTAR A FASE 6 (FECHAMENTO E GOVERNANÇA)

Você está implementando a Fase 6 do projeto ACOP em `gestao-modern`. Todo o design já foi feito antecipadamente e está em `docs/phase-6-design/`. Sua tarefa é implementar exatamente o que está especificado, não redesenhar.

Leia, nesta ordem, antes de escrever qualquer código:

```text
1. docs/phase-6-design/FASE_6_ARQUITETURA.md
2. docs/phase-6-design/FASE_6_MODELO_DADOS.md
3. docs/phase-6-design/FASE_6_STATE_MACHINE.md
4. docs/phase-6-design/FASE_6_RBAC.md
5. docs/phase-6-design/FASE_6_UI_UX.md
6. docs/phase-6-design/FASE_6_TEST_PLAN.md
7. docs/phase-6-design/FASE_6_PERGUNTAS_NEGOCIO.md
8. docs/phase-6-design/FASE_6_IMPLEMENTATION_PLAN.md
```

Leia também, para contexto do modelo existente que a Fase 6 estende:

```text
docs/architecture/ADR-009-persistent-reconciliation-model.md
docs/architecture/ADR-011-reconciliation-matching-engine.md
docs/architecture/ADR-012-reconciliation-exception-queue.md
app/Application/Reconciliation/ManualReconciliationService.php
app/Application/Reconciliation/ReconciliationSessionService.php
app/Providers/AppServiceProvider.php
routes/web.php (grupo reconciliacao-v2)
```

## Antes de começar

Execute e registre o baseline:

```powershell
git status
php artisan test --compact
php artisan route:list
vendor/bin/pint --test
composer validate --strict
composer audit --no-interaction
```

Confirme que o resultado é consistente com o último pacote de continuidade (93 testes/565 asserções em SQLite, 86 rotas, Pint/Composer limpos, salvo o que a homologação MariaDB tiver alterado desde então).

## Pendência de negócio — decisão obrigatória antes de 6.3

`FASE_6_PERGUNTAS_NEGOCIO.md` lista 14 perguntas sem resposta. Antes de implementar `ReconciliationClosureValidator` (etapa 6.3), verifique se `docs/architecture/` já contém um ADR respondendo essas perguntas (`ADR-01Y-reconciliation-closure-policy.md` ou similar).

- Se existir: implemente exatamente a política decidida.
- Se não existir: **pare e pergunte ao usuário** antes de assumir uma política. Não invente regra de negócio. Se o usuário autorizar explicitamente prosseguir sem resposta formal, use os defaults seguros já documentados em `FASE_6_STATE_MACHINE.md` (política Governada, sem four-eyes, sem prazo de fechamento, sem tolerância de saldo, sem `reconciliation:admin` extraordinário habilitado por padrão) e registre essa decisão provisória em um ADR antes de prosseguir.

## Ordem de execução

Siga `FASE_6_IMPLEMENTATION_PLAN.md` estritamente: 6.1 → 6.2 → 6.3 → 6.4 → 6.5 → 6.6 → (6.7 e 6.8 em paralelo com 6.2–6.6) → 6.9 → 6.10 → 6.11 → 6.12. Cada etapa tem um critério de aceite explícito no plano — não avance para a próxima etapa sem satisfazê-lo.

## Regras invioláveis durante toda a implementação

```text
Não alterar avt_lancamentos, avt_recebimentos, avt_movimentos, avt_conciliacoes.
Não acessar G:\xampp\htdocs\contas nem G:\xampp\htdocs\contasareceber.
Não usar banco, credenciais ou dados reais.
Não rodar migration real contra o servidor 192.168.0.220.
Não implementar auto-match, baixa automática, fechamento sem confirmação explícita,
tarifas, juros, estornos, Open Finance, CNAB novo ou IA financeira.
Não desativar /conciliacoes nem Contas a Pagar/Receber.
Não remover ou reduzir nenhuma proteção/validação das Fases 1–5.
Toda ação de fechamento/reabertura irreversível exige tela de confirmação — nunca um único clique.
Toda escrita de fechamento/reabertura passa por DB::transaction com lockForUpdate,
na ordem descrita em FASE_6_STATE_MACHINE.md §9.
reconciliation_closures nunca sofre UPDATE de conteúdo após criada — apenas
status/reopened_by/reopened_at, exatamente uma vez por reabertura (FASE_6_ARQUITETURA.md §6).
```

## Critério de conclusão da Fase 6

1. `FASE_6_TEST_PLAN.md` implementado integralmente, verde em SQLite;
2. suíte MariaDB (schema + concorrência da Fase 6) verde, quando ambiente disponível — se não estiver disponível no momento da implementação, documente como pendência exatamente como as Fases 1–5 fizeram (`NO-GO/BLOCKED` parcial só para a homologação MariaDB da Fase 6, não para o código);
3. `php artisan test --compact` sem regressão nas Fases 1–5;
4. Pint, `composer validate --strict`, `composer audit` limpos;
5. `RECONCILIATION_CLOSING_ENABLED=false` continua sendo o default, e com ele desligado o comportamento do sistema é idêntico ao pré-Fase 6;
6. `docs/architecture/ADR-01X-reconciliation-closure-model.md` e (se aplicável) `ADR-01Y-reconciliation-closure-policy.md` escritos;
7. `gestao-modern/IMPLEMENTACAO_FASE_6.md` escrito, no mesmo formato de `IMPLEMENTACAO_FASE_5.md`;
8. Nenhum commit/push/deploy automático — a regra de trabalho do projeto (nunca commitar sem pedido explícito) continua valendo também na Fase 6.

## Ao final

Produza um relatório no mesmo formato dos pacotes de continuidade anteriores (`HOMOLOGACAO_FASE_6.md`, `CHECKS_FINAIS_FASE_6.md`, `PACOTE_CONTINUACAO_FASE_6.md`), incluindo explicitamente:

- baseline antes/depois;
- lista de arquivos novos e alterados (a única alteração esperada em código de Fase 4/5 é a checagem `assertSessionOpenForWrite`/equivalente nos serviços listados em 6.4 — qualquer alteração além dessa deve ser justificada);
- resultado de todos os grupos de teste do `FASE_6_TEST_PLAN.md`;
- confirmação de que os sistemas protegidos não foram tocados;
- classificação final (esta fase implementada não é "produção liberada" — é apenas `Fase 6 implementada e homologada`, sujeita ao mesmo checklist de `docs/operations/PRE-PRODUCTION-READINESS.md` antes de qualquer deploy real).
