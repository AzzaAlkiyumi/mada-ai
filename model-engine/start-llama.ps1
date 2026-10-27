# Starts the local model server (llama.cpp) for development.
# Listens on 127.0.0.1 only and requires the API key from backend/.env.
# Stop it with Ctrl+C.

param(
    [string]$Model = 'Qwen3.5-4B-Q4_K_M.gguf',
    [string]$Alias = 'qwen3.5-4b',
    [int]$Context = 8192,
    [int]$Threads = 4
)

$root = $PSScriptRoot
$envFile = Join-Path $root '..\backend\.env'
$line = Select-String -Path $envFile -Pattern '^LLM_API_KEY=(.+)$' | Select-Object -First 1

if (-not $line) {
    Write-Error "LLM_API_KEY is empty in $envFile"
    exit 1
}

# Passed by environment variable so the key does not show in the process list.
$env:LLAMA_API_KEY = $line.Matches[0].Groups[1].Value.Trim()

& (Join-Path $root 'llama\llama-server.exe') `
    --model (Join-Path $root "models\$Model") `
    --alias $Alias `
    --host 127.0.0.1 `
    --port 8080 `
    --ctx-size $Context `
    --parallel 1 `
    --threads $Threads `
    --no-webui
