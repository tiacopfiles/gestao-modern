[CmdletBinding()]
param(
    [ValidateSet('provision', 'start', 'stop', 'destroy', 'status')]
    [string]$Action = 'status',

    # Raiz da instância descartável. Fora do repositório por padrão.
    [string]$InstanceRoot = (Join-Path $env:TEMP 'acop-mariadb101'),

    [int]$Port = 33101
)

# ---------------------------------------------------------------------------
# Opção C do runbook de homologação: instância MariaDB 10.1.48 portátil,
# descartável, em loopback, sem serviço Windows, sem Docker e sem instalação
# global. Usada quando Docker/Podman não estão operacionais no host.
#
# Credenciais: SINTÉTICAS. Este arquivo não contém segredo real e a instância
# não deve receber nenhum dado real.
# ---------------------------------------------------------------------------

$ErrorActionPreference = 'Stop'

$Version      = '10.1.48'
$ZipName      = "mariadb-$Version-winx64.zip"
$DownloadUrl  = "https://archive.mariadb.org/mariadb-$Version/winx64-packages/$ZipName"
$BinRoot      = Join-Path $InstanceRoot "mariadb-$Version-winx64"
$DataDir      = Join-Path $InstanceRoot 'data'
$ErrLog       = Join-Path $InstanceRoot 'mysqld.err'
$PidFile      = Join-Path $InstanceRoot 'mysqld.pid'

$RootPassword = 'synthetic-root-only-change-me'
$AppUser      = 'acop_hml'
$AppPassword  = 'synthetic-only-change-me'
$Databases    = @('acop_hml_mariadb101', 'acop_test_mariadb101')

function Test-PortOpen([int]$p) {
    Test-NetConnection -ComputerName '127.0.0.1' -Port $p -InformationLevel Quiet -WarningAction SilentlyContinue
}

function Assert-NotProduction {
    # Fail-closed: a instância portátil só pode existir em loopback e em porta
    # dedicada. 3306 é recusada para não colidir com um MariaDB real do host.
    if ($Port -eq 3306) {
        throw 'ABORT: porta 3306 recusada. Use uma porta dedicada (padrão 33101) para não colidir com instância real.'
    }
    if ($Port -lt 1024 -or $Port -gt 65535) {
        throw "ABORT: porta $Port fora da faixa local permitida."
    }
}

function Invoke-Sql([string]$sql, [string]$user = 'root', [string]$password = $RootPassword) {
    $mysql = Join-Path $BinRoot 'bin\mysql.exe'
    if (-not (Test-Path $mysql)) { throw "ABORT: cliente mysql.exe ausente. Rode -Action provision." }
    $sql | & $mysql -h 127.0.0.1 -P $Port -u $user "-p$password"
    if ($LASTEXITCODE -ne 0) { throw 'ABORT: comando SQL falhou.' }
}

function Do-Provision {
    Assert-NotProduction
    New-Item -ItemType Directory -Force -Path $InstanceRoot | Out-Null
    $zip = Join-Path $InstanceRoot $ZipName

    if (-not (Test-Path $zip)) {
        Write-Host "Baixando MariaDB $Version (~171 MB)..."
        $ProgressPreference = 'SilentlyContinue'
        Invoke-WebRequest -Uri $DownloadUrl -OutFile $zip -UseBasicParsing -TimeoutSec 1800
    }

    # Limpa a árvore anterior ANTES de medir o disco: uma extração interrompida
    # ocupa ~700 MB que serão devolvidos, e medir antes de liberar recusaria um
    # provision que na verdade cabe.
    Do-Stop
    if (Test-Path $BinRoot) { Remove-Item $BinRoot -Recurse -Force }

    # ~710 MB extraídos + datadir ≈ 900 MB (o ZIP já está em disco); 1,5 GB dá
    # margem. Sem essa checagem a extração falha no meio e o sintoma é um
    # bootstrap reclamando de `errmsg.sys`, que não aponta para a causa real.
    $drive = (Get-Item $InstanceRoot).PSDrive
    $freeGb = [math]::Round($drive.Free / 1GB, 2)
    if ($freeGb -lt 1.5) {
        throw "ABORT: apenas ${freeGb} GB livres em $($drive.Name):. A instância portátil precisa de ~1,5 GB de folga."
    }

    # Sempre reextrai: `provision` é a ação de setup, e reextrair elimina
    # qualquer resíduo de uma tentativa interrompida. O ZIP fica em cache, então
    # não há novo download.
    Write-Host 'Extraindo...'
    # ZipFile do .NET em vez de Expand-Archive: o `-Force` do Expand-Archive
    # falha ao limpar uma extração parcial quando o caminho vem na forma curta
    # 8.3 (ex.: BRENDO~1.CAS), e é bem mais lento para 171 MB.
    # ZipFile do .NET em vez de Expand-Archive: o `-Force` do Expand-Archive
    # falha ao limpar uma extração parcial quando o caminho vem na forma curta
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $InstanceRoot)

    # O pacote NÃO tem `share\errmsg.sys` na raiz — as mensagens são por idioma
    # (`share\english\errmsg.sys`). Os binários aparecem cedo na extração, então
    # checar só `mysqld.exe` não distingue árvore completa de interrompida.
    foreach ($required in @('bin\mysqld.exe', 'bin\mysql.exe', 'bin\mysql_install_db.exe', 'share\english\errmsg.sys')) {
        if (-not (Test-Path (Join-Path $BinRoot $required))) {
            throw "ABORT: extração incompleta, faltou $required. Verifique espaço em disco e rode provision de novo."
        }
    }

    $installDb = Join-Path $BinRoot 'bin\mysql_install_db.exe'
    if (-not (Test-Path $installDb)) { throw 'ABORT: mysql_install_db.exe não encontrado no pacote extraído.' }

    if (Test-Path $DataDir) {
        Write-Host 'Removendo datadir descartável anterior...'
        Do-Stop
        Remove-Item $DataDir -Recurse -Force
    }

    Write-Host 'Inicializando datadir...'
    & $installDb --datadir="$DataDir" --password="$RootPassword" --port=$Port
    if ($LASTEXITCODE -ne 0) { throw 'ABORT: falha ao inicializar o datadir.' }

    Do-Start

    Write-Host 'Criando databases e usuário sintéticos...'
    $statements = @()
    foreach ($db in $Databases) {
        $statements += "CREATE DATABASE IF NOT EXISTS $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        foreach ($h in @('127.0.0.1', 'localhost')) {
            $statements += "GRANT ALL PRIVILEGES ON $db.* TO '$AppUser'@'$h' IDENTIFIED BY '$AppPassword';"
        }
    }
    $statements += 'FLUSH PRIVILEGES;'
    Invoke-Sql ($statements -join "`n")

    Invoke-Sql 'SELECT VERSION() AS version, @@innodb_version AS innodb, @@tx_isolation AS isolation;'
    Write-Host "Instância pronta em 127.0.0.1:$Port"
}

function Do-Start {
    Assert-NotProduction
    if (Test-PortOpen $Port) { Write-Host "Já no ar em 127.0.0.1:$Port"; return }
    if (-not (Test-Path $DataDir)) { throw 'ABORT: datadir ausente. Rode -Action provision.' }

    $mysqld = Join-Path $BinRoot 'bin\mysqld.exe'
    $proc = Start-Process -FilePath $mysqld -PassThru -WindowStyle Hidden -RedirectStandardError $ErrLog -ArgumentList @(
        "--datadir=$DataDir",
        "--port=$Port",
        '--bind-address=127.0.0.1',
        '--skip-name-resolve',
        '--console'
    )
    $proc.Id | Set-Content -Path $PidFile

    foreach ($attempt in 1..30) {
        Start-Sleep -Seconds 2
        if (Test-PortOpen $Port) { Write-Host "MariaDB $Version no ar em 127.0.0.1:$Port (pid $($proc.Id))"; return }
    }
    if (Test-Path $ErrLog) { Get-Content $ErrLog -Tail 30 }
    throw 'ABORT: MariaDB descartável não ficou pronto.'
}

function Do-Stop {
    if (-not (Test-Path $PidFile)) { Write-Host 'Nenhum pid registrado.'; return }
    # Não usar $pid: é variável automática somente-leitura do PowerShell.
    $instancePid = (Get-Content $PidFile -ErrorAction SilentlyContinue | Select-Object -First 1)
    if ($instancePid) {
        $p = Get-Process -Id $instancePid -ErrorAction SilentlyContinue
        if ($p -and $p.Path -like "*mariadb-$Version-winx64*") {
            # Só encerra um processo que comprovadamente é esta instância portátil.
            Stop-Process -Id $instancePid -Force -Confirm:$false
            Write-Host "Instância portátil encerrada (pid $instancePid)."
        }
    }
    Remove-Item $PidFile -Force -ErrorAction SilentlyContinue
}

function Do-Destroy {
    Do-Stop
    if (Test-Path $DataDir) {
        Remove-Item $DataDir -Recurse -Force
        Write-Host 'Datadir descartável removido.'
    }
}

function Do-Status {
    Write-Host "InstanceRoot : $InstanceRoot"
    Write-Host "Binários     : $(if (Test-Path $BinRoot) { 'presentes' } else { 'ausentes' })"
    Write-Host "Datadir      : $(if (Test-Path $DataDir) { 'presente' } else { 'ausente' })"
    Write-Host "127.0.0.1:$Port : $(if (Test-PortOpen $Port) { 'ABERTO' } else { 'fechado' })"
    if (Test-PortOpen $Port) { Invoke-Sql 'SELECT VERSION() AS version, @@tx_isolation AS isolation;' }
}

switch ($Action) {
    'provision' { Do-Provision }
    'start'     { Do-Start }
    'stop'      { Do-Stop }
    'destroy'   { Do-Destroy }
    'status'    { Do-Status }
}
