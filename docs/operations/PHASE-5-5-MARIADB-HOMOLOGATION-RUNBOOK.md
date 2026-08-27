# Runbook — homologação MariaDB 10.1 da Fase 5.5

## Objetivo e limite

Reproduzir a homologação técnica das Fases 1–5 em um MariaDB 10.1 **descartável, sintético e local**. Este runbook não executa deploy nem autoriza produção.

Nunca acesse ou modifique:

```text
G:\xampp\htdocs\contas
G:\xampp\htdocs\contasareceber
avt_lancamentos reais
avt_recebimentos reais
avt_movimentos reais
avt_conciliacoes reais
```

## Pré-requisitos

- PHP e dependências Composer do projeto instalados;
- extensão `pdo_mysql`;
- MariaDB exatamente 10.1.x descartável em loopback; ou Docker já instalado para usar `mariadb:10.1.48`;
- nenhuma credencial ou dado real;
- working tree e backups do trabalho local inspecionados, sem commit/push automático.

Se MariaDB 10.1 ou Docker não estiver disponível, pare e registre `NO-GO / BLOCKED`. Não instale infraestrutura global e não substitua a prova por SQLite.

## Opção A — container gerenciado e isolado

O compose publicado em `tools/homologation/docker-compose.mariadb101.yml` usa porta loopback 33101, rede interna, `tmpfs`, credenciais sintéticas e nenhum volume persistente.

```powershell
cd gestao-modern
powershell -ExecutionPolicy Bypass -File tools\homologation\run-mariadb-homologation.ps1 -StartManagedContainer
```

O runner:

1. define somente variáveis sintéticas;
2. inicia o container fixado em 10.1.48;
3. aguarda no máximo cerca de 60 segundos;
4. executa o hard guard;
5. faz UP, DOWN e novo UP;
6. executa a suíte integral e os workers concorrentes;
7. executa baseline e `EXPLAIN`;
8. valida formatação e Composer.

Para incluir 10.000 registros:

```powershell
powershell -ExecutionPolicy Bypass -File tools\homologation\run-mariadb-homologation.ps1 -StartManagedContainer -IncludeLargeDataset
```

## Opção B — MariaDB descartável já existente

Somente use esta opção quando o proprietário confirmar que o database pode ser destruído e que está em loopback. Configure o processo atual, sem carregar `.env` real:

```powershell
$env:APP_ENV='homologation'
$env:DB_CONNECTION='mysql'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='PORTA_LOCAL'
$env:DB_DATABASE='acop_hml_identificador_sintetico'
$env:DB_USERNAME='usuario_sintetico'
$env:DB_PASSWORD='senha_sintetica'
$env:DB_PREFIX='avt_'
$env:DB_URL=''
$env:HOMOLOGATION_ALLOW_DESTRUCTIVE='I_UNDERSTAND_THIS_DATABASE_WILL_BE_DESTROYED'
```

Execute primeiro:

```powershell
php tools\homologation\guard.php --empty-target
```

O retorno deve informar `SAFE_HOMOLOGATION_TARGET`, MariaDB 10.1.x, host loopback e o database autorizado. O relatório não mostra senha. Qualquer recusa encerra o procedimento.

Depois execute:

```powershell
powershell -ExecutionPolicy Bypass -File tools\homologation\run-mariadb-homologation.ps1
```

## Opção C — instância portátil sem Docker (usada na homologação de 17/08/2026)

Use quando Docker/Podman não estiverem operacionais. Baixa o ZIP oficial
`mariadb-10.1.48-winx64` de `archive.mariadb.org`, inicializa um datadir
descartável **fora do repositório**, sobe `mysqld` em `127.0.0.1:33101` sem
serviço Windows e cria os databases sintéticos. Não instala nada globalmente e
recusa a porta 3306 para não colidir com uma instância real do host.

```powershell
cd gestao-modern
powershell -ExecutionPolicy Bypass -File tools\homologation\local-mariadb101.ps1 -Action provision
. tools\homologation\env-mariadb101.ps1
php tools\homologation\guard.php --empty-target
powershell -ExecutionPolicy Bypass -File tools\homologation\run-mariadb-homologation.ps1
```

Cleanup obrigatório ao final (remove datadir e encerra apenas o processo desta
instância portátil, verificado pelo caminho do executável):

```powershell
powershell -ExecutionPolicy Bypass -File tools\homologation\local-mariadb101.ps1 -Action destroy
```

`-Action status` mostra se a instância está no ar. A versão é idêntica à fixada
no compose, então a Opção A continua preferida quando o daemon voltar a
funcionar.

**Pré-requisito de disco: ~2,5 GB livres** (171 MB de ZIP, ~710 MB extraídos, mais
o datadir). O script recusa provisionar abaixo disso. Sem essa checagem, a
extração falha no meio e o sintoma é um bootstrap reclamando de
`share\errmsg.sys` — mensagem que não aponta para a causa real.

### `APP_ENV` precisa ser `testing`

O guard aceita `testing` e `homologation`, mas o Laravel só desliga a validação
de CSRF quando o ambiente é exatamente `testing`. Com `homologation`, toda rota
POST responde 419 e a suíte passa a medir o harness em vez do produto.
`homologation` não tem nenhum significado em `config/` — é apenas um token de
intenção. `env-mariadb101.ps1` já define `testing`.

Definir no shell é obrigatório, não basta o `phpunit.xml`:
`Env::getRepository()` do Laravel consulta `getenv()` antes de `$_SERVER`, então
uma variável posta no shell vence o `force="true"` do PHPUnit. A proteção do
banco não depende disso — vem do nome `acop_hml_*`/`acop_test_*`, do host
loopback, da versão 10.1.x e de `HOMOLOGATION_ALLOW_DESTRUCTIVE`, todos
verificados pelo guard a cada teste.

## Execução manual auditável

Se for necessário separar as etapas, repita o guard imediatamente antes de cada comando destrutivo:

```powershell
php tools\homologation\guard.php --empty-target
php artisan migrate:fresh --force
php artisan migrate:status

php tools\homologation\guard.php
php artisan migrate:reset --force

php tools\homologation\guard.php --empty-target
php artisan migrate --force

vendor\bin\phpunit.bat -c phpunit.mariadb.xml
php tools\homologation\guard.php
php tools\homologation\performance-baseline.php
vendor\bin\pint.bat --test
composer validate --strict
composer audit --no-interaction
```

Nunca rode `migrate:fresh`, `migrate:reset`, DROP ou o baseline quando o guard não tiver acabado de passar.

## Evidências obrigatórias

Registre sem senha ou token:

- `SELECT VERSION()`, `DATABASE()`, hostname sanitizado e `@@tx_isolation`;
- 21 migrations no primeiro UP, rollback e reaplicação;
- tabelas, engine, collation, PKs, FKs, delete rules, uniques e índices pelo `information_schema`;
- `DECIMAL(15,2)`, round-trip de 0,01 / 0,10 / 999.999,99 e residual 100/3;
- 104 casos da suíte MariaDB, totais de testes e asserções;
- início simultâneo e resultado dos processos concorrentes;
- ausência de duplicação, over-allocation, double match e efeitos no legado;
- timings dos volumes e planos `EXPLAIN` completos;
- comportamento com flags OFF, V2 isolada, ambas habilitadas e kill switch;
- confirmação de zero escrita nos sistemas e tabelas protegidos.

Cada grupo deve ser `PASS`, `FAIL`, `BLOCKED` ou `WARNING`. Qualquer perda de centavos, duplicação indevida, double match, over-allocation, corrupção de idempotência, rollback destrutivo, regressão crítica ou escrita no legado é blocker automático.

## Interpretação de concorrência

`tests/Homologation/MariaDbConcurrencyHomologationTest.php` inicia dois processos PHP independentes por cenário. O teste recusa workers que não iniciem próximos no tempo e trata qualquer resultado inesperado como falha. Não substitua isso por duas chamadas sequenciais.

Verifique especialmente deadlocks e timeouts nos logs. Não adicione retry automático apenas para esconder falhas; qualquer retry precisa de justificativa e teste de idempotência.

## Performance

O baseline é observacional:

```powershell
php tools\homologation\performance-baseline.php
php tools\homologation\performance-baseline.php --include-10000
```

Ele recria apenas o database aprovado pelo guard, usa dados sintéticos, limita o pool de matching conforme a configuração e emite JSON com timings e `EXPLAIN`. Não publique os tempos como SLA e não calibre regras de negócio com esses dados.

## Cleanup

Não remova banco/container que não tenha sido criado por esta homologação.

Para o container iniciado por este runner, o cleanup explícito pode ser feito no mesmo comando com `-CleanupManagedContainer` ou manualmente:

```powershell
docker compose -f tools\homologation\docker-compose.mariadb101.yml down --volumes --remove-orphans
```

Confirme o arquivo compose e o nome do projeto Docker antes do comando. O compose não monta os diretórios dos sites e usa somente `tmpfs`.

## Fechamento

Atualize `HOMOLOGACAO_FASE_5_5.md`, `CHECKS_FINAIS_FASE_5_5.md` e `PACOTE_CONTINUACAO_FASE_5_5.md` com resultados reais. Somente declare `GO PARA FASE 6` quando todos os critérios mínimos estiverem comprovados. GO para a Fase 6 ainda não significa pronto para produção.
