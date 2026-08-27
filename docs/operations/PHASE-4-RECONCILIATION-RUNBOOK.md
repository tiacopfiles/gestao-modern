# Runbook — Conciliação persistente e manual (Fase 4)

## Objetivo e limite de segurança

Operar a conciliação v2 manual, aditiva e persistente dentro de `gestao-modern`. Este runbook não autoriza acessar ou modificar `G:\xampp\htdocs\contas` e `G:\xampp\htdocs\contasareceber`, aplicar migrations sem janela aprovada, escrever em `avt_lancamentos`, `avt_recebimentos`, `avt_movimentos` ou `avt_conciliacoes`, dar baixa automática ou alterar fatos bancários.

A conciliação legada em `/conciliacoes` continua em paralelo. A v2 não possui endpoints em `/api/v1`.

## Kill switch e configuração

O padrão seguro é:

```dotenv
RECONCILIATION_V2_ENABLED=false
RECONCILIATION_V2_VIEW_USER_IDS=
RECONCILIATION_V2_MANAGE_USER_IDS=
```

Os IDs são separados por vírgula. `MANAGE` também permite visualizar; `VIEW` sozinho não permite criar sessão, confirmar ou desfazer. Listas vazias não concedem acesso a ninguém. Após editar ambiente em instalação com configuração cacheada, execute o procedimento operacional aprovado para reconstruir o cache.

Em qualquer anomalia, defina `RECONCILIATION_V2_ENABLED=false` e recarregue a configuração da aplicação. Isso deve devolver `404` nas sete rotas v2 e ocultar o menu, sem afetar Contas a Pagar, Contas a Receber, APIs v1, importação bancária ou `/conciliacoes`.

## Pré-requisitos

1. Backup restaurável, janela e responsável aprovados.
2. Ambiente-alvo confirmado, PHP/Laravel e MariaDB 10.1.10 identificados.
3. `DB_PREFIX=avt_` revisado quando aplicável.
4. Contagens/checksums das quatro tabelas protegidas registrados antes da janela.
5. Feature flag desligada e allowlists vazias durante a migration.
6. Testes, lint, Pint, Composer, `migrate:status` e SQL pretendido aprovados.
7. Homologação de locks e compatibilidade em uma cópia isolada do MariaDB concluída antes de produção.

Pare imediatamente se qualquer SQL pretendido contiver alteração de tabela legada protegida.

## Migrations da Fase 4

- `2026_08_13_000130_create_reconciliation_sessions_table`;
- `2026_08_13_000140_create_reconciliation_matches_table`;
- `2026_08_13_000150_create_reconciliation_match_titles_table`;
- `2026_08_13_000160_create_reconciliation_match_transactions_table`.

São quatro criações aditivas. As FKs apontam apenas para tabelas modernas. Conta e usuários legados são validados pela aplicação e não recebem FK.

Aplicação controlada, nunca automática em produção:

```bash
php artisan migrate:status
php artisan migrate --pretend --force
php artisan migrate --force
php artisan migrate:status
```

Depois, valide presença de tabelas, índices, FKs, prefixo e invariância das tabelas protegidas. Mantenha a flag desligada até terminar o smoke test.

## Liberação gradual

1. Adicione um único usuário de homologação a `RECONCILIATION_V2_VIEW_USER_IDS` e `RECONCILIATION_V2_MANAGE_USER_IDS`.
2. Ative `RECONCILIATION_V2_ENABLED=true` somente na homologação.
3. Confirme que `/conciliacoes` e as 13 operações `/api/v1` continuam disponíveis.
4. Execute um caso 1:1 sintético e confira auditoria, disponibilidade e ausência de settlement.
5. Desfaça o match e confirme que ele permanece no histórico e libera a disponibilidade.
6. Amplie usuários somente após evidências e aprovação.

## Operação da interface

### Criar sessão

Abra `/reconciliacao-v2`, escolha “Nova sessão”, informe uma conta existente e início/fim. Não é permitido repetir exatamente conta e período. `OPEN` e `IN_REVIEW` são estados operacionais, não fechamento.

### Criar match

Abra a sessão. Use filtros explícitos de título/parcela e transação. Selecione ao menos uma linha de cada lado, informe valores positivos e confirme. O servidor revalida conta, período bancário, parcela, cancelamento, direção, moeda, disponibilidade e igualdade exata em centavos dentro de transação SQL.

Regras principais:

- PAYABLE somente com DEBIT; RECEIVABLE somente com CREDIT;
- não misturar tipos/direções no mesmo match;
- transação deve pertencer à conta e ao período da sessão;
- título pode ter vencimento anterior ao período, mas precisa da mesma conta explícita;
- título parcelado exige parcela concreta;
- os dois totais devem ser iguais; diferenças e tarifas não são inferidas;
- confirmar não liquida título e não muda transação.

### Desfazer

Abra o detalhe do match confirmado, informe motivo de até 1000 caracteres e use “Desfazer sem excluir”. O registro passa para `VOIDED`, guarda ator/data/motivo e deixa de consumir disponibilidade. Nenhum settlement é estornado.

## Auditoria e logs

Cruze `correlation_id`, usuário, sessão e match nos eventos:

- `RECONCILIATION_SESSION_CREATED`;
- `RECONCILIATION_MATCH_CONFIRMED`;
- `RECONCILIATION_MATCH_VOIDED`;
- log estruturado `reconciliation_v2_operation`.

O snapshot de confirmação registra IDs e valores alocados. Logs operacionais não incluem descrição bancária completa, dados de parte ou credenciais.

## Troubleshooting

- v2 retorna `404`: flag desligada ou cache de configuração antigo;
- v2 retorna `403`: usuário fora da allowlist correspondente;
- `RECONCILIATION_SESSION_DUPLICATE`: reutilize a sessão da mesma conta/período;
- `RECONCILIATION_ACCOUNT_MISMATCH`: título ou transação pertence a outra conta;
- `RECONCILIATION_TRANSACTION_OUTSIDE_PERIOD`: ajuste a sessão ou selecione transação dentro do período;
- `RECONCILIATION_INSTALLMENT_REQUIRED`: selecione uma parcela concreta;
- `RECONCILIATION_DIRECTION_MISMATCH`: use PAYABLE/DEBIT ou RECEIVABLE/CREDIT;
- `RECONCILIATION_UNBALANCED`: ajuste alocações até igualdade exata;
- `*_OVER_ALLOCATED`: atualize a tela; outro match pode ter consumido o saldo;
- deadlock/timeout: não repita em massa; preserve a correlação, desative a flag se recorrente e investigue locks/índices no MariaDB.

## Rollback e recuperação

Se houver falha funcional, use primeiro o kill switch; ele é reversível e não apaga histórico. Reverta a versão da aplicação pelo procedimento aprovado, mantendo tabelas novas.

Não execute rollback de migrations depois de existir qualquer sessão/match: os `down()` apagariam dados de auditoria modernos. Em banco isolado e descartável, as quatro migrations podem ser revertidas em ordem inversa. Em produção, recuperação de dados usa backup aprovado, nunca `DELETE` manual e nunca toca tabelas protegidas.

## Homologação obrigatória no MariaDB 10.1

Validar migrations e `down()` em banco descartável, nomes/tamanhos de índices, `RESTRICT`, DECIMAL e prefixo. Executar duas confirmações realmente simultâneas contra a mesma transação/parcela e comprovar que somente o total disponível é aceito, sem 500 e sem over-allocation. Repetir criação simultânea da mesma sessão. Conferir invariância dos títulos, settlements, banco e quatro tabelas legadas antes/depois.

