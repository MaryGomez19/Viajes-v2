$ErrorActionPreference = 'Continue'

function Get-Proc([int] $ProcId) {
    if ($ProcId -le 0) { return $null }
    Get-CimInstance Win32_Process -Filter "ProcessId=$ProcId" -ErrorAction SilentlyContinue
}

$killed = @{}

function Stop-Tree([int] $ProcId, [string] $Reason) {
    if ($ProcId -le 4) { return }
    if ($script:killed.ContainsKey($ProcId)) { return }
    $alive = Get-Process -Id $ProcId -ErrorAction SilentlyContinue
    if (-not $alive) { return }
    Write-Host "Deteniendo $Reason (PID $ProcId)"
    & taskkill.exe /F /T /PID $ProcId | Out-Null
    $script:killed[$ProcId] = $true
}

# Ventanas que abrio iniciar.bat
$cmds = Get-CimInstance Win32_Process -Filter "Name = 'cmd.exe'" -ErrorAction SilentlyContinue | Where-Object {
    $cl = $_.CommandLine
    if (-not $cl) { return $false }
    ($cl -match 'artisan\s+serve' -and $cl -match '8000') -or
    ($cl -match 'AgenciaSueV2' -and $cl -match 'npm\s+run\s+dev')
}

foreach ($cmd in $cmds) {
    Stop-Tree -ProcId $cmd.ProcessId -Reason 'ventana del servidor'
}

# Si se iniciaron a mano, mata solo el proceso del servidor
$servers = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
    $cl = $_.CommandLine
    if (-not $cl) { return $false }
    ($cl -match 'artisan\s+serve' -and $cl -match '8000' -and $cl -match 'Viajes-v2') -or
    ($cl -match 'vite' -and $cl -match 'AgenciaSueV2')
}

foreach ($server in $servers) {
    Stop-Tree -ProcId $server.ProcessId -Reason $server.Name
}

if ($killed.Count -eq 0) {
    Write-Host 'No habia servidores de Laravel ni de AgenciaSueV2 en ejecucion.'
} else {
    Write-Host 'Listo. Laravel y el frontend quedaron apagados.'
}
