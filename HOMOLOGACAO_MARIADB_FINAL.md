# Homologação MariaDB 10.1 — estado final

```text
STATUS: PASS
Data: 17/08/2026
Servidor: MariaDB 10.1.48 (InnoDB 5.6.49-89.0), loopback 127.0.0.1:33101
Database: acop_hml_mariadb101 (sintético, descartável), prefixo avt_
Isolamento: REPEATABLE-READ
```

Substitui o estado `BLOCKED EXTERNALLY` registrado em 14/08/2026 e encerra o gate
aberto desde a Fase 5.5. O bloqueio era exclusivamente de infraestrutura e foi
removido sem Docker.

## Como o bloqueio foi removido

O daemon do Docker Desktop continua inoperante nesta máquina: o CLI existe
(`29.7.2`) e o pipe `dockerDesktopLinuxEngine` responde, mas o Engine devolve
`500 Internal Server Error` em qualquer rota da API e não existe distro
`docker-desktop` no WSL — a instalação nunca completou o first-run. Iniciar o
Docker Desktop não resolveu.

Como o gate exige apenas "MariaDB 10.1.x descartável em loopback", foi criada uma
terceira opção, sem Docker e sem instalação global:

**Opção C — instância portátil.** `tools/homologation/local-mariadb101.ps1` baixa
o ZIP oficial `mariadb-10.1.48-winx64` de `archive.mariadb.org`, inicializa um
datadir descartável fora do repositório, sobe `mysqld` em `127.0.0.1:33101` sem
serviço Windows e cria `acop_hml_mariadb101`/`acop_test_mariadb101` com usuário
sintético.

```powershell
powershell -ExecutionPolicy Bypass -File tools\homologation\local-mariadb101.ps1 -Action provision
. tools\homologation\env-mariadb101.ps1
powershell -ExecutionPolicy Bypass -File tools\homologation\run-mariadb-homologation.ps1
powershell -ExecutionPolicy Bypass -File tools\homologation\local-mariadb101.ps1 -Action destroy
```

A versão é exatamente a mesma fixada em `docker-compose.mariadb101.yml`, então a
Opção A (Docker) continua válida e preferida quando o daemon voltar a funcionar.
O script recusa a porta 3306 para nunca colidir com uma instância real.

### Estado da instância e ressalva sobre o script

A instância foi **destruída após a homologação**, como o runbook exige: nenhum
`mysqld` em execução, porta 33101 fechada, datadir e binários removidos.

Ressalva honesta sobre `local-mariadb101.ps1`: cada operação que ele executa
(download, extração, `mysql_install_db`, start em loopback, criação de databases e
usuário sintético) foi executada e comprovada nesta sessão — foi sobre essa
instância que a homologação inteira rodou. O script empacota exatamente essa
sequência, e três defeitos dele foram encontrados e corrigidos no caminho: uso de
`$pid` (variável somente-leitura do PowerShell), `Expand-Archive -Force` falhando
em caminho 8.3, e um guard que aceitava extração parcial. **Uma re-execução
completa de `-Action provision` de ponta a ponta não pôde ser concluída depois
disso porque a máquina ficou sem espaço em disco** (0 GB livres; ver abaixo). Na
próxima execução, rodar `-Action provision` primeiro e conferir o resultado antes
de confiar no restante.

### Espaço em disco — atenção operacional

Esta máquina está com o disco C: praticamente cheio (110 GB usados). Durante a
sessão chegou a 0 GB livres, e é isso que quebra a extração do MariaDB portátil
com um erro enganoso (`Can't find messagefile ... share\errmsg.sys`). O script
agora recusa provisionar com menos de 2,5 GB livres. Liberar espaço nesta máquina
é uma pendência operacional independente deste projeto.

## Ciclo executado

| Etapa | Resultado | Status |
| --- | --- | --- |
| Guard pré-UP (`--empty-target`) | `SAFE_HOMOLOGATION_TARGET` | PASS |
| UP inicial | 26/26 migrations | PASS |
| `migrate:status` | 26 `Ran`, 0 `Pending` | PASS |
| DOWN completo (`migrate:reset`) | 26/26 revertidas | PASS |
| Guard pós-DOWN | alvo vazio novamente | PASS |
| Segundo UP (`migrate`) | 26/26 migrations | PASS |
| Suíte integral `phpunit.mariadb.xml` | 127 testes / 938 asserções | PASS |
| Baseline observacional + `EXPLAIN` | executado | PASS |
| `pint --test` | sem divergências | PASS |
| `composer validate --strict` | válido | PASS |
| `composer audit` | nenhuma vulnerabilidade | PASS |

O DOWN devolve o schema a vazio de verdade — o guard `--empty-target` volta a
aprovar o alvo depois do rollback, o que prova que nenhuma migration deixa
resíduo.

## Concorrência real (processos independentes)

`tests/Homologation/MariaDbConcurrencyHomologationTest.php` — 11 cenários, cada
um com dois processos PHP separados e início sincronizado. Não são chamadas
sequenciais.

| Cenário | Resultado |
| --- | --- |
| Mesma Idempotency-Key + mesmo payload | 1 título, 1 replay — PASS |
| Mesma chave + payload diferente | 201 + 409, sem corrupção — PASS |
| `external_id` igual, chaves diferentes | 1 título — PASS |
| Mesma identidade bancária simultânea | 1 transação — PASS |
| Fatos legítimos iguais com `external_id` A/B | 2 transações — PASS |
| Mesmo OFX simultâneo | 1 lote, 1 linha por FITID — PASS |
| Consumo total simultâneo de R$ 1.000 | 1 match + 1 recusa — PASS |
| Over-allocation R$ 600 + R$ 600 | 1 match + 1 recusa — PASS |
| Aceite simultâneo do mesmo candidato | 1 match — PASS |
| Geração de matching determinística/dedup | PASS |
| **Fase 6 — dois `close()` na mesma sessão** | 1 fechamento + `CLOSURE_SESSION_ALREADY_CLOSED` — PASS |
| **Fase 6 — dois `reopen()` no mesmo fechamento** | 1 reabertura + `CLOSURE_NOT_CLOSED` — PASS |
| **Fase 6 — `close()` concorrente com `confirm()`** | nenhum match CONFIRMED fora do snapshot — PASS |
| **Fase 6 — `close()` de períodos sobrepostos** | 1 fechamento + `CLOSURE_PERIOD_OVERLAP` — PASS |

Os quatro cenários da Fase 6 não existiam antes desta execução: a documentação os
exigia, mas a suíte não os cobria. O de períodos sobrepostos é o mais relevante,
porque a exclusão mútua ali não vem de um registro já existente para travar — vem
do gap lock do InnoDB sobre a faixa do índice `recon_closures_account_period_idx`.
Isso é impossível de comprovar em SQLite e agora está comprovado.

**Resultado de concorrência: PASS.**

## Defeitos encontrados e corrigidos nesta homologação

O gate justificou-se: a suíte SQLite estava verde e o MariaDB reprovou 40 casos
na primeira execução (33 erros + 7 falhas).

1. **`ReconciliationAllocationQuery` — bug de produção, severidade alta.**
   `selectRaw('allocation.title_installment_id, SUM(allocation.allocated_amount)')`
   não passa pelo grammar, então não recebia o prefixo que o alias recebe
   (`avt_allocation`). Resultado: `Unknown column 'allocation.title_installment_id'`
   em qualquer banco com prefixo — ou seja, **confirmação e desfazimento de match
   estariam quebrados em produção**, que usa `avt_`. Corrigido qualificando a
   coluna via `getQueryGrammar()->wrap()`.
2. **`phpunit.xml` rodava com `DB_PREFIX=""`.** É por isso que o item 1 atravessou
   cinco fases invisível. A suíte rápida agora usa `avt_`, igual a produção.
3. **`MigrationSafetyTest` incompatível com MariaDB 10.1.** `Schema::getColumnType()`
   do Laravel consulta `generation_expression`, coluna que só existe a partir do
   10.2. Substituído por introspecção via `information_schema`, que além de
   funcionar no alvo real também verifica precisão e escala (`DECIMAL(15,2)`).
4. **`phpunit.mariadb.xml` com `APP_ENV=homologation`.** O Laravel só desliga a
   validação de CSRF quando o ambiente é exatamente `testing`, então toda rota
   POST respondia 419 e a suíte media o harness, não o produto. `homologation`
   não tem nenhum significado em `config/` — era só um token de intenção, e o
   guard já aceitava `testing`.
5. **Tabelas legadas sintéticas estruturalmente infiéis.** As testemunhas de "não
   foi tocado" tinham apenas `id`/`marker`, enquanto `/dashboard` lê
   `valor_total`/`situacao`. Centralizadas em `tests/Support/CreatesLegacyWitnessTables.php`
   com o schema de `tools/demo/setup-sqlite.php`.
6. **`concurrency-worker.php` usava o `UploadedFile` do Symfony.**
   `Request::convertUploadedFiles()` chama `UploadedFile::createFromBase()`, que
   reconstrói instâncias Symfony com `$test = false` e descarta a flag de teste;
   o arquivo sintético então falhava em `is_uploaded_file()` e o cenário OFX
   morria em 422 `validation.uploaded`, sem nunca exercitar a corrida.
7. **Expectativa do teste OFX contradizia a fixture.** Esperava 2 transações;
   `statement-valid.ofx` tem 3 FITIDs. O valor agora é derivado da própria
   fixture. O teste nunca havia sido executado, então a constante errada nunca
   apareceu.

Nenhum teste foi relaxado para passar. As correções 1 e 3 são de produto/portabilidade;
2, 4, 5, 6 e 7 corrigem instrumentos de medição que estavam mentindo.

## Ressalva importante sobre o valor de um verde em SQLite

O SQLite reinterpreta identificador desconhecido entre aspas duplas como literal
string. `sum("coluna_inexistente")` devolve `0` em vez de erro, e
`where "coluna_inexistente" != '4'` é sempre verdadeiro. **Portanto um verde na
suíte SQLite não prova que uma coluna existe.** Só `phpunit.mariadb.xml` prova
schema. Isso está registrado como comentário em `phpunit.xml` para não se perder.

## Performance e EXPLAIN

Baseline observacional executado sobre datasets sintéticos de 100 e 1.000
registros (10.000 opcional via `-IncludeLargeDataset`). Nenhum tempo é declarado
como SLA.

Índices confirmados em uso pelo otimizador:

- `reconciliation_candidate_queue_idx` — `type: ref`, `Using where; Using index`;
- `reconciliation_exception_queue_idx` — `type: ref`, `Using where; Using index`.

Ponto de atenção, não blocker: o plano de `transaction_allocations` faz
`type: ALL` com `Using temporary; Using filesort` sobre
`reconciliation_match_transactions`. No volume sintético a tabela tem 1 linha, e
com 1 linha o otimizador ignora índice por decisão de custo — o plano não é
conclusivo. Reavaliar com volume real antes de tratar como problema; nenhum
índice foi adicionado especulativamente.

## Proteções confirmadas

```text
Contas a Pagar acessado? NÃO
Contas a Receber acessado? NÃO
G:\xampp\htdocs\contas modificado? NÃO
G:\xampp\htdocs\contasareceber modificado? NÃO
Banco de produção (192.168.0.220) acessado? NÃO
avt_lancamentos / avt_recebimentos / avt_movimentos / avt_conciliacoes reais alteradas? NÃO
Migrations aplicadas em banco compartilhado? NÃO
Dados reais utilizados? NÃO
Software instalado globalmente? NÃO — ZIP portátil em diretório temporário, sem serviço Windows
```

## Declaração final

```text
MariaDB 10.1 homologado (Fases 1-6)? SIM
Concorrência real validada? SIM
UP / DOWN / UP limpos? SIM
Bug de produção encontrado e corrigido? SIM (prefixo em SQL cru)
Produção autorizada por esta homologação? NÃO
```

Esta homologação remove o blocker técnico de infraestrutura e de concorrência.
**Não autoriza produção**: as pendências restantes são de negócio (14 perguntas da
Fase 6), de operação (backup/restore e observabilidade em ambiente real) e de
autorização humana. Ver `PENDENCIAS_FINAIS_PROJETO.md`.
