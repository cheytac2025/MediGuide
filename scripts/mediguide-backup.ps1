<#
.SYNOPSIS
    Creates and verifies a private MediGuide MySQL/MariaDB backup.

.DESCRIPTION
    backup   Consistent mysqldump (single transaction, routines, triggers,
             events) into an access-restricted folder outside the web root,
             plus a SHA-256 checksum file.
    verify   Restores a dump into a NEW isolated database and compares schema,
             indexes, foreign keys, row counts, and CHECKSUM TABLE values with
             the source database. Prints counts and hashes only, never data.

    Credentials are read from the project .env and passed through a temporary
    option file (never on the command line). APP_KEY is never read or written.
    The restored database is not dropped automatically.

.EXAMPLE
    powershell -File scripts\mediguide-backup.ps1 -Action backup
    powershell -File scripts\mediguide-backup.ps1 -Action verify -DumpFile D:\MediGuide_Secure_Backups\mediguide-20261027-103000.sql
#>
param(
    [Parameter(Mandatory)][ValidateSet('backup', 'verify')][string]$Action,
    [string]$DumpFile,
    [string]$BackupDir = 'D:\MediGuide_Secure_Backups',
    [string]$MysqlBin = 'D:\Xampp\mysql\bin',
    [string]$ProjectDir
)

$ErrorActionPreference = 'Stop'

if (-not $ProjectDir) {
    $ProjectDir = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
}

# Volatile framework tables change while the app is running; they are compared
# by schema only and reported separately.
$VolatileTables = @('sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs')

function Read-DbSettings {
    $settings = @{ DB_HOST = '127.0.0.1'; DB_PORT = '3306'; DB_DATABASE = ''; DB_USERNAME = ''; DB_PASSWORD = '' }

    foreach ($line in Get-Content (Join-Path $ProjectDir '.env')) {
        if ($line -match '^(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=(.*)$') {
            $settings[$Matches[1]] = $Matches[2].Trim().Trim('"')
        }
    }

    if (-not $settings.DB_DATABASE) { throw 'DB_DATABASE is not set in .env.' }

    return $settings
}

function Assert-PrivateBackupDir {
    $full = [IO.Path]::GetFullPath($BackupDir).TrimEnd('\')
    $project = [IO.Path]::GetFullPath($ProjectDir).TrimEnd('\')
    $webRoot = [IO.Path]::GetFullPath((Join-Path $ProjectDir '..')).TrimEnd('\')

    if ($full.StartsWith($project, 'OrdinalIgnoreCase') -or $full.StartsWith($webRoot, 'OrdinalIgnoreCase')) {
        throw "Backup directory must be outside the web root and repository ($webRoot)."
    }

    if (-not (Test-Path $full)) {
        New-Item -ItemType Directory -Path $full | Out-Null
    }

    # Owner and SYSTEM only; no inherited access for other local accounts.
    & icacls $full /inheritance:r /grant:r "$($env:USERDOMAIN)\$($env:USERNAME):(OI)(CI)F" 'SYSTEM:(OI)(CI)F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not restrict backup directory permissions.' }

    return $full
}

function New-OptionFile([hashtable]$db, [string]$dir) {
    $path = Join-Path $dir ('.client-' + [guid]::NewGuid().ToString('N') + '.cnf')
    $lines = @('[client]', "host=$($db.DB_HOST)", "port=$($db.DB_PORT)", "user=$($db.DB_USERNAME)")
    if ($db.DB_PASSWORD) { $lines += "password=$($db.DB_PASSWORD)" }
    [IO.File]::WriteAllLines($path, $lines)

    return $path
}

function Invoke-Sql([string]$optionFile, [string]$database, [string]$sql) {
    $output = & (Join-Path $MysqlBin 'mysql.exe') "--defaults-extra-file=$optionFile" --batch --skip-column-names "--database=$database" "--execute=$sql" 2>&1
    if ($LASTEXITCODE -ne 0) { throw "SQL failed against [$database]: $($output | Out-String)" }

    return , [string[]]@($output | ForEach-Object { "$_" })
}

function Get-Fingerprint([string]$optionFile, [string]$database) {
    $schema = "TABLE_SCHEMA = '$database'"

    return [ordered]@{
        Tables  = Invoke-Sql $optionFile $database "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE $schema ORDER BY TABLE_NAME"
        Columns = Invoke-Sql $optionFile $database "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT,'<null>'), EXTRA FROM information_schema.COLUMNS WHERE $schema ORDER BY TABLE_NAME, ORDINAL_POSITION"
        Indexes = Invoke-Sql $optionFile $database "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE $schema ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
        ForeignKeys = Invoke-Sql $optionFile $database "SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.$schema AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION"
    }
}

function Get-TableStats([string]$optionFile, [string]$database, [string[]]$tables) {
    $stats = [ordered]@{}

    foreach ($table in $tables) {
        $count = (Invoke-Sql $optionFile $database "SELECT COUNT(*) FROM ``$table``")[0]
        $checksum = ((Invoke-Sql $optionFile $database "CHECKSUM TABLE ``$table`` EXTENDED")[0] -split "`t")[1]
        $stats[$table] = "$count|$checksum"
    }

    return $stats
}

function Get-Hash([string[]]$lines) {
    $bytes = [Text.Encoding]::UTF8.GetBytes(($lines -join "`n"))
    return -join ([Security.Cryptography.SHA256]::Create().ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') })
}

$db = Read-DbSettings
$dir = Assert-PrivateBackupDir
$optionFile = New-OptionFile $db $dir

try {
    if ($Action -eq 'backup') {
        $engines = Invoke-Sql $optionFile $db.DB_DATABASE "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'"
        if ([int]$engines[0] -ne 0) { throw 'Non-InnoDB tables found; --single-transaction would not give a consistent snapshot.' }

        $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
        $target = Join-Path $dir "$($db.DB_DATABASE)-$stamp.sql"

        & (Join-Path $MysqlBin 'mysqldump.exe') "--defaults-extra-file=$optionFile" `
            --single-transaction --quick --routines --triggers --events --hex-blob `
            --default-character-set=utf8mb4 --no-tablespaces "--result-file=$target" $db.DB_DATABASE
        if ($LASTEXITCODE -ne 0) { throw 'mysqldump failed.' }

        $tail = Get-Content $target -Tail 1
        if ($tail -notmatch '-- Dump completed') { throw 'Dump file is incomplete.' }

        $hash = (Get-FileHash $target -Algorithm SHA256).Hash.ToLower()
        Set-Content -Path "$target.sha256" -Value "$hash  $(Split-Path -Leaf $target)" -Encoding ascii

        Write-Output "Backup: $target"
        Write-Output "Size: $((Get-Item $target).Length) bytes"
        Write-Output "SHA-256: $hash"

        return
    }

    if (-not $DumpFile -or -not (Test-Path $DumpFile)) { throw 'Provide -DumpFile pointing to an existing dump.' }

    $expected = ((Get-Content "$DumpFile.sha256") -split '\s+')[0]
    $actual = (Get-FileHash $DumpFile -Algorithm SHA256).Hash.ToLower()
    if ($expected -ne $actual) { throw 'Dump checksum mismatch; the backup file is corrupted or altered.' }
    Write-Output 'Checksum: OK'

    $restoreDb = "$($db.DB_DATABASE)_restore_verify_" + (Get-Date -Format 'yyyyMMddHHmmss')
    $exists = Invoke-Sql $optionFile 'information_schema' "SELECT COUNT(*) FROM SCHEMATA WHERE SCHEMA_NAME = '$restoreDb'"
    if ([int]$exists[0] -ne 0) { throw "Database $restoreDb already exists." }

    Invoke-Sql $optionFile 'information_schema' "CREATE DATABASE ``$restoreDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" | Out-Null
    Write-Output "Restore database: $restoreDb"

    $source = $DumpFile.Replace('\', '/')
    Invoke-Sql $optionFile $restoreDb "SOURCE $source" | Out-Null

    $live = Get-Fingerprint $optionFile $db.DB_DATABASE
    $restored = Get-Fingerprint $optionFile $restoreDb
    $failures = 0

    foreach ($part in $live.Keys) {
        $same = (Get-Hash $live[$part]) -eq (Get-Hash $restored[$part])
        if (-not $same) { $failures++ }
        Write-Output ("{0,-12} live={1,4} restored={2,4} {3}" -f $part, $live[$part].Count, $restored[$part].Count, $(if ($same) { 'MATCH' } else { 'DIFFERENT' }))
    }

    $tables = $live.Tables | ForEach-Object { ($_ -split "`t")[0] }
    $liveStats = Get-TableStats $optionFile $db.DB_DATABASE $tables
    $restoredStats = Get-TableStats $optionFile $restoreDb $tables

    foreach ($table in $tables) {
        $liveCount, $liveSum = $liveStats[$table] -split '\|'
        $restoredCount, $restoredSum = $restoredStats[$table] -split '\|'
        $volatile = $VolatileTables -contains $table
        $status = if ($liveSum -eq $restoredSum) { 'MATCH' } elseif ($volatile) { 'CHANGED (volatile, expected)' } else { 'DIFFERENT' }
        if ($status -eq 'DIFFERENT') { $failures++ }
        Write-Output ("{0,-24} rows live={1,5} restored={2,5} checksum {3}" -f $table, $liveCount, $restoredCount, $status)
    }

    if ($failures -gt 0) { throw "Verification failed: $failures difference(s). Restored database left in place for inspection: $restoreDb" }

    Write-Output "VERIFIED. Restored database retained (not dropped): $restoreDb"
}
finally {
    Remove-Item -LiteralPath $optionFile -Force -ErrorAction SilentlyContinue
}
