[CmdletBinding()]
param(
    [switch]$StartManagedContainer,
    [switch]$IncludeLargeDataset,
    [switch]$CleanupManagedContainer
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ComposeFile = Join-Path $PSScriptRoot 'docker-compose.mariadb101.yml'
$GuardScript = Join-Path $PSScriptRoot 'guard.php'
$PerformanceScript = Join-Path $PSScriptRoot 'performance-baseline.php'
$StartedHere = $false

function Assert-CommandAvailable([string]$Name) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Pré-requisito ausente: $Name. A homologação MariaDB 10.1 permanece BLOCKED/NO-GO."
    }
}

function Invoke-Guard([switch]$RequireEmptyTarget) {
    $arguments = @($GuardScript)
    if ($RequireEmptyTarget) {
        $arguments += '--empty-target'
    }
    & php @arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Guard de homologação recusou o alvo. Nenhuma operação destrutiva foi autorizada.'
    }
}

function Invoke-Checked([string[]]$Arguments) {
    & $Arguments[0] $Arguments[1..($Arguments.Count - 1)]
    if ($LASTEXITCODE -ne 0) {
        throw "Comando falhou: $($Arguments -join ' ')"
    }
}

Set-Location -LiteralPath $ProjectRoot
Assert-CommandAvailable 'php'

try {
    if ($StartManagedContainer) {
        Assert-CommandAvailable 'docker'
        # 'testing' e não 'homologation': o guard aceita ambos, mas o Laravel só
        # desliga a validação de CSRF da suíte quando APP_ENV é exatamente
        # 'testing'. Ver tools/homologation/env-mariadb101.ps1 para o detalhe.
        $env:APP_ENV = 'testing'
        $env:DB_CONNECTION = 'mysql'
        $env:DB_HOST = '127.0.0.1'
        $env:DB_PORT = '33101'
        $env:DB_DATABASE = 'acop_hml_mariadb101'
        $env:DB_USERNAME = 'acop_hml'
        $env:DB_PASSWORD = 'synthetic-only-change-me'
        $env:DB_PREFIX = 'avt_'
        $env:DB_URL = ''
        $env:HOMOLOGATION_ALLOW_DESTRUCTIVE = 'I_UNDERSTAND_THIS_DATABASE_WILL_BE_DESTROYED'

        & docker compose -f $ComposeFile up -d
        if ($LASTEXITCODE -ne 0) {
            throw 'Não foi possível iniciar o MariaDB 10.1 descartável.'
        }
        $StartedHere = $true

        $guardReady = $false
        foreach ($attempt in 1..30) {
            & php $GuardScript --empty-target *> $null
            if ($LASTEXITCODE -eq 0) {
                $guardReady = $true
                break
            }
            Start-Sleep -Seconds 2
        }
        if (-not $guardReady) {
            throw 'MariaDB 10.1 descartável não ficou pronto ou foi recusado pelo guard.'
        }
    }

    Write-Host '1/7 Guard pré-UP (alvo vazio e sem tabelas protegidas)'
    Invoke-Guard -RequireEmptyTarget

    Write-Host '2/7 UP inicial'
    Invoke-Checked @('php', 'artisan', 'migrate:fresh', '--force')
    Invoke-Checked @('php', 'artisan', 'migrate:status')

    Write-Host '3/7 DOWN completo'
    Invoke-Guard
    Invoke-Checked @('php', 'artisan', 'migrate:reset', '--force')

    Write-Host '4/7 segundo UP'
    Invoke-Guard -RequireEmptyTarget
    Invoke-Checked @('php', 'artisan', 'migrate', '--force')

    Write-Host '5/7 suíte integral e homologação de concorrência real'
    Invoke-Checked @('vendor\bin\phpunit.bat', '-c', 'phpunit.mariadb.xml')

    Write-Host '6/7 baseline observacional e EXPLAIN'
    Invoke-Guard
    $performanceArguments = @('php', $PerformanceScript)
    if ($IncludeLargeDataset) {
        $performanceArguments += '--include-10000'
    }
    Invoke-Checked $performanceArguments

    Write-Host '7/7 validações estáticas finais'
    Invoke-Checked @('vendor\bin\pint.bat', '--test')
    Invoke-Checked @('composer', 'validate', '--strict')

    Write-Host 'HOMOLOGAÇÃO EXECUTADA. Analise os resultados; a execução isoladamente não autoriza produção.'
} finally {
    if ($CleanupManagedContainer -and $StartedHere) {
        & docker compose -f $ComposeFile down --volumes --remove-orphans
    }
}
