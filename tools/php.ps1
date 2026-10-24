$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$installed = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
$candidates = @(
    $env:GAME_PHP_BINARY,
    (Join-Path $workspace 'AI-Workspace\Work\v1\wechat-login-20261023\runtime\php83\php.exe'),
    $(if ($installed) { $installed.Source })
)
$selected = $null
foreach ($candidate in $candidates) {
    if ($candidate -and (Test-Path -LiteralPath $candidate -PathType Leaf)) {
        & $candidate -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);'
        if ($LASTEXITCODE -eq 0) { $selected = $candidate; break }
    }
}
if (-not $selected) { throw 'PHP 8+ is required. Set GAME_PHP_BINARY to a PHP 8 executable.' }
& $selected @args
exit $LASTEXITCODE
