<#
  setup.ps1 — konfigurasi setelah installer menyalin file (DECISIONS.md #39).
  Dijalankan installer sebagai Administrator; aman dijalankan ulang (mis. saat update).

  -Root  folder program, mis. C:\Program Files\JamSholat (berisi app, apache, php, python, mpg123)
  -Data  folder data, mis. C:\ProgramData\JamSholat (database, poster, foto kamera, cadangan)
#>
param(
    [Parameter(Mandatory = $true)][string]$Root,
    [Parameter(Mandatory = $true)][string]$Data
)
$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path $Root).Path.TrimEnd('\')
$R = $Root -replace '\\', '/'
$D = $Data.TrimEnd('\') -replace '\\', '/'
$Service = 'JamSholatApache'

foreach ($dir in 'storage\captures', 'storage\backups', 'storage\sessions', 'uploads\publications', 'logs') {
    New-Item -ItemType Directory -Force -Path (Join-Path $Data $dir) | Out-Null
}
# Layanan alarm & kamera berjalan sebagai pengguna yang masuk, jadi Users perlu bisa menulis.
& icacls $Data /grant '*S-1-5-32-545:(OI)(CI)M' /T /Q | Out-Null
$Log = Join-Path $Data 'logs\setup.log'
Start-Transcript -Path $Log -Append | Out-Null

# --- PHP ----------------------------------------------------------------------
@"
[PHP]
engine = On
extension_dir = "$R/php/ext"
extension = pdo_sqlite
extension = sqlite3
extension = mbstring
extension = gd
extension = fileinfo
extension = exif
extension = openssl
upload_max_filesize = 20M
post_max_size = 24M
memory_limit = 256M
max_execution_time = 60
display_errors = Off
log_errors = On
error_log = "$D/logs/php-error.log"
date.timezone = UTC
session.use_strict_mode = 1
"@ | Set-Content -Encoding ASCII (Join-Path $Root 'php\php.ini')

# --- Konfigurasi aplikasi: data di ProgramData, program di Program Files -----
@"
<?php
// Dibuat oleh installer Windows (packaging/windows/setup.ps1). Akan ditimpa saat update.
return [
    'db_path' => '$D/storage/jamsholat.db',
    'publications_path' => '$D/uploads/publications',
    'captures_path' => '$D/storage/captures',
    'backups_path' => '$D/storage/backups',
    'session_path' => '$D/storage/sessions',
    'publication_max_bytes' => 20 * 1024 * 1024,
    'publication_allowed_mime' => ['image/jpeg', 'image/png'],
    'admin_session_days' => 30,
];
"@ | Set-Content -Encoding UTF8 (Join-Path $Root 'app\config\config.php')

$Php = Join-Path $Root 'php\php.exe'
$Db = Join-Path $Data 'storage\jamsholat.db'
& $Php (Join-Path $Root 'app\scripts\init_db_cli.php') $Db
if ($LASTEXITCODE -ne 0) { throw 'Database gagal dibuat.' }

# --- Apache -------------------------------------------------------------------
# Layanan Windows tidak mencari DLL di folder PHP (PATH layanan baru berubah setelah
# restart), jadi DLL pendukung ekstensi (libsqlite3, libcrypto, ...) dimuat eksplisit.
# libcrypto lebih dulu karena dipakai DLL lain.
$libs = Get-ChildItem (Join-Path $Root 'php') -Filter 'lib*.dll' |
    Sort-Object @{ Expression = { if ($_.Name -like 'libcrypto*') { 0 } else { 1 } } }, Name
$loadFiles = ($libs | ForEach-Object { "LoadFile `"$R/php/$($_.Name)`"" }) -join "`r`n"
$allow = 'Require ip 127.0.0.1 ::1 192.168.0.0/16 10.0.0.0/8 172.16.0.0/12 fe80::/10 fc00::/7'
@"
# Dibuat oleh installer Windows Jam Sholat Masjid (packaging/windows/setup.ps1).
Define SRVROOT "$R/apache"
ServerRoot "`${SRVROOT}"
Listen 80
ServerName localhost
LoadModule access_compat_module modules/mod_access_compat.so
LoadModule alias_module modules/mod_alias.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule env_module modules/mod_env.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule setenvif_module modules/mod_setenvif.so
$loadFiles
LoadModule php_module "$R/php/php8apache2_4.dll"
PHPIniDir "$R/php"
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
TypesConfig conf/mime.types
DirectoryIndex index.php index.html
LogFormat "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"" combined
ErrorLog "$D/logs/apache-error.log"
CustomLog "$D/logs/apache-access.log" combined
<Directory />
    AllowOverride None
    Require all denied
</Directory>
# REQ-NF-122: hanya jaringan lokal masjid.
DocumentRoot "$R/app/public"
<Directory "$R/app/public">
    AllowOverride All
    $allow
</Directory>
Alias /uploads/publications "$D/uploads/publications"
<Directory "$D/uploads/publications">
    Options None
    $allow
    <FilesMatch "\.php$">
        Require all denied
    </FilesMatch>
</Directory>
"@ | Set-Content -Encoding ASCII (Join-Path $Root 'apache\conf\httpd.conf')

# Port 80 dipakai program lain (IIS, http.sys, Skype lama)? Catat supaya mudah dicari.
$busy = Get-NetTCPConnection -LocalPort 80 -State Listen -ErrorAction SilentlyContinue
foreach ($c in $busy) {
    $proc = Get-Process -Id $c.OwningProcess -ErrorAction SilentlyContinue
    Write-Warning "Port 80 sudah dipakai: PID $($c.OwningProcess) $($proc.ProcessName) ($($c.LocalAddress))"
}

$Httpd = Join-Path $Root 'apache\bin\httpd.exe'
& $Httpd -t
if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi Apache tidak valid.' }
if (-not (Get-Service $Service -ErrorAction SilentlyContinue)) {
    & $Httpd -k install -n $Service
}
Set-Service $Service -StartupType Automatic
Restart-Service $Service -ErrorAction SilentlyContinue
if ((Get-Service $Service).Status -ne 'Running') {
    Start-Service $Service
}

# HP pengurus membuka panel lewat WiFi: izinkan port 80 (Apache tetap membatasi ke LAN).
& netsh advfirewall firewall delete rule name="Jam Sholat Masjid (HTTP)" | Out-Null
& netsh advfirewall firewall add rule name="Jam Sholat Masjid (HTTP)" dir=in action=allow protocol=TCP localport=80 | Out-Null

# Hitung jadwal hari ini & jalankan migrasi database sekarang.
try {
    Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost/api/schedule.php' -TimeoutSec 30 | Out-Null
} catch {
    Write-Warning "API belum merespons: $($_.Exception.Message). Cek $D/logs/apache-error.log"
}
Stop-Transcript | Out-Null
