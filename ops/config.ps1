Set-StrictMode -Version 2.0

function Resolve-PhotoboxPath {
    param(
        [Parameter(Mandatory = $true)][string]$BasePath,
        [Parameter(Mandatory = $true)][string]$Value
    )

    if ([string]::IsNullOrWhiteSpace($Value)) {
        return $Value
    }

    if ([System.IO.Path]::IsPathRooted($Value)) {
        return [System.IO.Path]::GetFullPath($Value)
    }

    return [System.IO.Path]::GetFullPath((Join-Path $BasePath $Value))
}

function Convert-PhpArrayValue {
    param(
        [Parameter(Mandatory = $true)][string]$RawValue,
        [Parameter(Mandatory = $true)][string]$ConfigFileDir
    )

    $value = $RawValue.Trim().TrimEnd(',').Trim()

    if ($value -match "^'(?<str>.*)'$") {
        return $Matches['str']
    }

    if ($value -match '^"(?<str>.*)"$') {
        return $Matches['str']
    }

    if ($value -match '^-?\d+$') {
        return [int]$value
    }

    if ($value -eq 'true') {
        return $true
    }

    if ($value -eq 'false') {
        return $false
    }

    if ($value -match "__DIR__\s*\.\s*'(?<suffix>[^']+)'") {
        $suffix = $Matches['suffix'].Replace('/', [System.IO.Path]::DirectorySeparatorChar)
        return [System.IO.Path]::GetFullPath((Join-Path $ConfigFileDir $suffix))
    }

    return $value
}

function Read-PhpConfigArray {
    param([Parameter(Mandatory = $true)][string]$Path)

    $result = @{}
    if (-not (Test-Path -LiteralPath $Path)) {
        return $result
    }

    $content = Get-Content -LiteralPath $Path -Raw
    $configDir = Split-Path -Parent $Path
    $matches = [regex]::Matches($content, "'(?<key>[a-zA-Z0-9_]+)'\s*=>\s*(?<value>[^\r\n]+)")

    foreach ($match in $matches) {
        $key = $match.Groups['key'].Value
        $rawValue = $match.Groups['value'].Value
        $result[$key] = Convert-PhpArrayValue -RawValue $rawValue -ConfigFileDir $configDir
    }

    return $result
}

function Get-PhotoboxConfig {
    param([Parameter(Mandatory = $true)][string]$RepoRoot)

    $examplePath = Join-Path $RepoRoot 'shared/config.example.php'
    $localPath = Join-Path $RepoRoot 'shared/config.php'

    $cfg = Read-PhpConfigArray -Path $examplePath
    if (Test-Path -LiteralPath $localPath) {
        $localCfg = Read-PhpConfigArray -Path $localPath
        foreach ($key in $localCfg.Keys) {
            $cfg[$key] = $localCfg[$key]
        }
    }

    if (-not $cfg.ContainsKey('port')) { $cfg['port'] = 8080 }
    if (-not $cfg.ContainsKey('printer_name')) { $cfg['printer_name'] = '' }
    if (-not $cfg.ContainsKey('camera_idle_minutes')) { $cfg['camera_idle_minutes'] = 30 }
    if (-not $cfg.ContainsKey('import_mode')) { $cfg['import_mode'] = 'watch_folder' }
    if (-not $cfg.ContainsKey('sd_card_path')) { $cfg['sd_card_path'] = '' }

    $cfg['data_path'] = Resolve-PhotoboxPath -BasePath $RepoRoot -Value ([string]$cfg['data_path'])
    $cfg['watch_path'] = Resolve-PhotoboxPath -BasePath $RepoRoot -Value ([string]$cfg['watch_path'])
    if (-not [string]::IsNullOrWhiteSpace([string]$cfg['sd_card_path'])) {
        $cfg['sd_card_path'] = Resolve-PhotoboxPath -BasePath $RepoRoot -Value ([string]$cfg['sd_card_path'])
    }

    $importMode = ([string]$cfg['import_mode']).ToLowerInvariant()
    if ($importMode -notin @('watch_folder', 'sd_card')) {
        $importMode = 'watch_folder'
    }
    $cfg['import_mode'] = $importMode

    if ($cfg['import_mode'] -eq 'sd_card') {
        if ([string]::IsNullOrWhiteSpace([string]$cfg['sd_card_path'])) {
            $cfg['import_source_path'] = $cfg['watch_path']
        } else {
            $cfg['import_source_path'] = [string]$cfg['sd_card_path']
        }
    } else {
        $cfg['import_source_path'] = [string]$cfg['watch_path']
    }

    $cfg['db_path'] = Join-Path $cfg['data_path'] 'queue/photobox.sqlite'
    $cfg['logs_path'] = Join-Path $cfg['data_path'] 'logs'
    $cfg['repo_root'] = $RepoRoot

    return [pscustomobject]$cfg
}
