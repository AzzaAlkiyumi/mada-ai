# Builds the React app and packs a release archive for the server.
#
#   powershell -ExecutionPolicy Bypass -File deploy\package-release.ps1
#
# The archive holds backend/ (without vendor, .env, storage or tests),
# frontend-dist/ (the built React app) and deploy/ (server scripts).
# Secrets and local data are never included.

param(
    [string]$Out = (Join-Path $PSScriptRoot '..\rafeeq-release.tar.gz')
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$stage = Join-Path ([IO.Path]::GetTempPath()) ("rafeeq-release-" + [guid]::NewGuid().ToString('N'))

try {
    Write-Host '==> Building the frontend'
    Push-Location (Join-Path $root 'frontend')
    npm ci --no-fund --no-audit
    if ($LASTEXITCODE -ne 0) { throw 'npm ci failed' }
    npm run build
    if ($LASTEXITCODE -ne 0) { throw 'npm run build failed' }
    Pop-Location

    Write-Host '==> Collecting files'
    $backend = Join-Path $root 'backend'
    robocopy $backend (Join-Path $stage 'backend') /E /NFL /NDL /NJH /NJS /NP `
        /XD (Join-Path $backend 'vendor') (Join-Path $backend 'node_modules') (Join-Path $backend 'storage') `
            (Join-Path $backend 'bootstrap\cache') (Join-Path $backend 'tests') `
        /XF .env .env.backup .env.local database.sqlite .phpunit.result.cache CLAUDE.md AGENTS.md | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }

    robocopy (Join-Path $root 'frontend\dist') (Join-Path $stage 'frontend-dist') /E /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }

    robocopy (Join-Path $root 'deploy') (Join-Path $stage 'deploy') /E /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }

    # Guard against shipping secrets by mistake.
    $leaks = Get-ChildItem $stage -Recurse -Force -File | Where-Object { $_.Name -eq '.env' -or $_.Extension -eq '.gguf' }
    if ($leaks) { throw "Refusing to pack secret or model files: $($leaks.FullName -join ', ')" }

    Write-Host '==> Packing'
    if (Test-Path -LiteralPath $Out) { Remove-Item -LiteralPath $Out }
    tar -czf $Out -C $stage backend frontend-dist deploy
    if ($LASTEXITCODE -ne 0) { throw 'tar failed' }

    try {
        $file = Get-Item -LiteralPath $Out
        Write-Host ("Release ready: {0} ({1:N2} MB)" -f $file.FullName, ($file.Length / 1MB))
    }
    catch {
        # Windows PowerShell cannot stat paths over 260 characters; the archive is still there.
        Write-Host "Release ready: $Out"
    }
}
finally {
    if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
}
