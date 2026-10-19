<#
  build.ps1 — membuat JamSholat-Setup-<versi>.exe (DECISIONS.md #39).

    pwsh packaging\windows\build.ps1 -Version 1.1.0 -AudioDir <folder audio> -OutDir dist

  Mengunduh Apache, PHP, Python embeddable, mpg123, dan VC++ Runtime (versi dikunci di
  bawah), menyusun folder staging, memasang OpenCV ke Python, lalu mengompilasi
  JamSholat.iss dengan Inno Setup 6.5+ (iscc). Dijalankan di Windows, mis. GitHub Actions.
#>
param(
    [Parameter(Mandatory = $true)][string]$Version,
    [Parameter(Mandatory = $true)][string]$AudioDir,
    [string]$OutDir = 'dist',
    [string]$CacheDir = "$env:TEMP\jamsholat-build-cache"
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Invoke-WebRequest jauh lebih cepat tanpa bilah progres

# Versi komponen dikunci supaya hasil build bisa diulang.
$Components = @{
    apache = 'https://www.apachelounge.com/download/VS17/binaries/httpd-2.4.66-251206-Win64-VS17.zip'
    php    = 'https://downloads.php.net/~windows/releases/archives/php-8.4.26-Win32-vs17-x64.zip'
    python = 'https://www.python.org/ftp/python/3.12.10/python-3.12.10-embed-amd64.zip'
    mpg123 = 'https://www.mpg123.de/download/win64/1.33.7/mpg123-1.33.7-x86-64.zip'
    redist = 'https://aka.ms/vs/17/release/vc_redist.x64.exe'
}
$PhpFallback = 'https://downloads.php.net/~windows/releases/php-8.4.26-Win32-vs17-x64.zip'

$Repo = (Resolve-Path "$PSScriptRoot\..\..").Path
$Stage = Join-Path $env:TEMP "jamsholat-stage"
New-Item -ItemType Directory -Force -Path $CacheDir, $OutDir | Out-Null
if (Test-Path $Stage) { Remove-Item -Recurse -Force $Stage }
New-Item -ItemType Directory -Force -Path $Stage | Out-Null

function Get-Component([string]$Name, [string]$Url, [string]$Fallback = '') {
    $file = Join-Path $CacheDir ([IO.Path]::GetFileName(([Uri]$Url).AbsolutePath))
    if (-not (Test-Path $file)) {
        Write-Host "Mengunduh $Name ..."
        try {
            Invoke-WebRequest -Uri $Url -OutFile $file -UserAgent 'Mozilla/5.0 (jamsholat-build)'
        } catch {
            if (-not $Fallback) { throw }
            Write-Host "  sumber utama gagal, mencoba $Fallback"
            Invoke-WebRequest -Uri $Fallback -OutFile $file -UserAgent 'Mozilla/5.0 (jamsholat-build)'
        }
    }
    return $file
}

function Expand-Into([string]$Zip, [string]$Dest, [string]$Inner = '') {
    $tmp = Join-Path $env:TEMP ("unz-" + [guid]::NewGuid())
    Expand-Archive -Path $Zip -DestinationPath $tmp
    $src = if ($Inner) { Join-Path $tmp $Inner } else { $tmp }
    New-Item -ItemType Directory -Force -Path $Dest | Out-Null
    Copy-Item -Recurse -Force "$src\*" $Dest
    Remove-Item -Recurse -Force $tmp
}

# --- Komponen --------------------------------------------------------------
Expand-Into (Get-Component 'Apache' $Components.apache) "$Stage\apache" 'Apache24'
Expand-Into (Get-Component 'PHP' $Components.php $PhpFallback) "$Stage\php"
Expand-Into (Get-Component 'Python' $Components.python) "$Stage\python"
$mpgZip = Get-Component 'mpg123' $Components.mpg123
$mpgTmp = Join-Path $env:TEMP 'mpg123-unz'
if (Test-Path $mpgTmp) { Remove-Item -Recurse -Force $mpgTmp }
Expand-Archive $mpgZip $mpgTmp
$mpgExe = Get-ChildItem -Recurse $mpgTmp -Filter mpg123.exe | Select-Object -First 1
New-Item -ItemType Directory -Force "$Stage\mpg123" | Out-Null
Copy-Item -Recurse -Force "$($mpgExe.DirectoryName)\*" "$Stage\mpg123"
New-Item -ItemType Directory -Force "$Stage\redist" | Out-Null
Copy-Item (Get-Component 'VC++ Runtime' $Components.redist) "$Stage\redist\vc_redist.x64.exe"

# Apache Lounge menyertakan contoh situs & dokumentasi yang tidak dipakai.
Remove-Item -Recurse -Force "$Stage\apache\htdocs", "$Stage\apache\manual", "$Stage\apache\cgi-bin" -ErrorAction SilentlyContinue

# --- Python: OpenCV untuk kamera (wheel biner, tanpa compile) ----------------
$pth = Get-ChildItem "$Stage\python" -Filter 'python*._pth' | Select-Object -First 1
Add-Content $pth.FullName "Lib\site-packages"
& python -m pip install --quiet --only-binary=:all: --platform win_amd64 --python-version 3.12 `
    --target "$Stage\python\Lib\site-packages" opencv-python-headless==4.10.0.84 numpy==2.1.3
if ($LASTEXITCODE -ne 0) { throw 'pip gagal memasang OpenCV.' }

# --- Aplikasi ---------------------------------------------------------------
$exclude = @('.git', '.github', 'tests', 'storage', 'packaging\debian', 'packaging\linux',
             'public\uploads', 'public\assets\audio', '__pycache__')
# Jangan pakai /XF config.php: Windows tidak membedakan huruf besar-kecil, sehingga
# src\Config.php ikut terlewat. Konfigurasi lokal dihapus dari tempatnya saja.
& robocopy $Repo "$Stage\app" /E /NFL /NDL /NJH /NJS /NP /XD ($exclude | ForEach-Object { Join-Path $Repo $_ }) /XF '.DS_Store' '.gitignore' | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy aplikasi gagal ($LASTEXITCODE)" }
Remove-Item "$Stage\app\config\config.php", "$Stage\app\config\.env" -ErrorAction SilentlyContinue
foreach ($f in 'bootstrap.php', 'src\Config.php', 'src\Db\Database.php', 'public\display.php', 'alarm_service\alarm_service.py') {
    if (-not (Test-Path "$Stage\app\$f")) { throw "File aplikasi hilang dari staging: $f" }
}
& robocopy $AudioDir "$Stage\app\public\assets\audio" /E /NFL /NDL /NJH /NJS /NP /XF '*.wav' | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy audio gagal ($LASTEXITCODE)" }
Copy-Item "$Repo\public\assets\audio\README.md" "$Stage\app\public\assets\audio\" -ErrorAction SilentlyContinue
foreach ($f in 'adzan.mp3', 'adzan_subuh.mp3', 'iqomah.mp3') {
    if (-not (Test-Path "$Stage\app\public\assets\audio\$f")) { throw "Audio tidak lengkap: $f" }
}

# --- Installer ---------------------------------------------------------------
$iscc = (Get-Command iscc.exe -ErrorAction SilentlyContinue).Source
if (-not $iscc) { $iscc = "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe" }
& $iscc /Qp "/DAppVersion=$Version" "/DStage=$Stage" "/O$((Resolve-Path $OutDir).Path)" "$PSScriptRoot\JamSholat.iss"
if ($LASTEXITCODE -ne 0) { throw 'Inno Setup gagal.' }
Get-ChildItem $OutDir -Filter 'JamSholat-Setup-*.exe' | Format-Table Name, Length
