# ---------------------------------------------------------------------------
# Define no processo atual as variáveis de homologação MariaDB 10.1 descartável.
# Dot-source este arquivo antes do guard/runner:
#
#   . tools\homologation\env-mariadb101.ps1
#   php tools\homologation\guard.php --empty-target
#
# Todas as credenciais são SINTÉTICAS e correspondem à instância descartável
# criada por docker-compose.mariadb101.yml ou por local-mariadb101.ps1.
# Nenhuma credencial real deve ser colocada aqui.
# ---------------------------------------------------------------------------

param(
    [int]$Port = 33101,
    [ValidateSet('hml', 'test')]
    [string]$Target = 'hml'
)

# APP_ENV=testing, não 'homologation'.
#
# O guard aceita ambos, e 'homologation' não tem nenhum significado em config/ —
# é apenas um token de intenção. Já 'testing' é exigido pelo Laravel para
# `runningUnitTests()`, que desliga a validação de CSRF na suíte. Com
# 'homologation' toda rota POST responde 419 e a suíte passa a medir o harness
# em vez do produto.
#
# Definir aqui (e não só no phpunit.xml) é obrigatório: `Env::getRepository()`
# do Laravel consulta `getenv()` antes de `$_SERVER`, então uma variável posta
# no shell vence o `force="true"` do PHPUnit.
#
# A proteção do banco NÃO depende deste valor: continua vindo do database
# `acop_hml_*`/`acop_test_*`, do host loopback, da versão 10.1.x e do
# HOMOLOGATION_ALLOW_DESTRUCTIVE — todos verificados pelo guard a cada teste.
$env:APP_ENV     = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST     = '127.0.0.1'
$env:DB_PORT     = "$Port"
$env:DB_DATABASE = "acop_${Target}_mariadb101"
$env:DB_USERNAME = 'acop_hml'
$env:DB_PASSWORD = 'synthetic-only-change-me'
$env:DB_PREFIX   = 'avt_'
$env:DB_URL      = ''
$env:HOMOLOGATION_ALLOW_DESTRUCTIVE = 'I_UNDERSTAND_THIS_DATABASE_WILL_BE_DESTROYED'

# Fase 6: o fechamento precisa estar habilitado para que a suíte de homologação
# exercite closures/reopenings no banco-alvo.
$env:RECONCILIATION_V2_ENABLED      = 'true'
$env:RECONCILIATION_MATCHING_ENABLED = 'true'
$env:RECONCILIATION_CLOSING_ENABLED = 'true'

Write-Host "Homologação apontada para $($env:DB_HOST):$($env:DB_PORT)/$($env:DB_DATABASE) (prefixo $($env:DB_PREFIX))"
