<#
  test-upgrade.ps1: uji upgrade installer Windows (DECISIONS.md #40): pasang versi
  lama, isi data lewat panel, jalankan sesi alarm lama (file program sedang dipakai),
  pasang versi baru di atasnya, lalu pastikan data utuh dan fitur baru berjalan.
    pwsh packaging\windows\test-upgrade.ps1 -Old JamSholat-Setup-1.1.1.exe -New dist\JamSholat-Setup-1.1.2.exe
#>
param([Parameter(Mandatory = $true)][string]$Old, [Parameter(Mandatory = $true)][string]$New, [switch]$Fresh)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$script:Fail = 0
function Pass($m) { Write-Host "  ✓ $m" }
function Fail($m) { Write-Host "  ✗ $m"; $script:Fail = 1 }
$Root = "$env:ProgramFiles\JamSholat"
$Data = "$env:ProgramData\JamSholat"

function Install($exe, $log) {
    $p = Start-Process -FilePath $exe -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', "/LOG=$log" -Wait -PassThru
    return $p.ExitCode
}
function Base { $port = (Get-Content "$Data\port.txt" -ErrorAction SilentlyContinue | Select-Object -First 1); if (-not $port -or $port -eq '80') { 'http://localhost' } else { "http://localhost:$port" } }
function Csrf($session, $url) {
    $r = Invoke-WebRequest -UseBasicParsing -Uri $url -WebSession $session
    if ($r.Content -match 'name="csrf_token" value="([^"]+)"') { $Matches[1] } else { throw "csrf tidak ada di $url" }
}
# Baca/ubah database lewat PHP bawaan (tanpa sqlite3.exe). Kode PHP ditulis ke berkas:
# PowerShell 5.1 merusak tanda kutip pada argumen php -r (DECISIONS.md #39).
function Sql($query) {
    $php = "$env:TEMP\jamsholat-sql.php"
    "<?php `$pdo = new PDO('sqlite:' . `$argv[1]); `$r = `$pdo->query(`$argv[2]); echo `$r ? implode('|', (array) `$r->fetch(PDO::FETCH_NUM)) : '';" | Set-Content -Encoding ASCII $php
    (& "$Root\php\php.exe" $php "$Data\storage\jamsholat.db" $query | Out-String).Trim()
}

Write-Host "== upgrade $(Split-Path $Old -Leaf) -> $(Split-Path $New -Leaf) =="
# -Fresh (hanya runner CI): buang data sisa uji sebelumnya agar akun pertama bisa dibuat.
if ($Fresh -and (Test-Path $Data)) { Remove-Item -Recurse -Force $Data }
if ((Install $Old "$env:TEMP\setup-old.log") -eq 0) { Pass 'versi lama terpasang' } else { Fail 'installer lama gagal'; exit 1 }
$B = Base

# Data seperti dipakai takmir.
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$t = Csrf $s "$B/admin/setup.php"
Invoke-WebRequest -UseBasicParsing -Uri "$B/admin/setup.php" -WebSession $s -Method Post -Body @{ csrf_token = $t; username = 'takmir'; password = 'rahasia123'; confirm_password = 'rahasia123' } | Out-Null
$t = Csrf $s "$B/admin/settings_location.php"
Invoke-WebRequest -UseBasicParsing -Uri "$B/admin/settings_location.php" -WebSession $s -Method Post -Body @{ csrf_token = $t; location_name = 'Duri, Riau'; latitude = '1.257955'; longitude = '101.195658'; madzhab = 'syafii' } | Out-Null
Sql "UPDATE mosque_settings SET mosque_name = 'Masjid Uji Upgrade'" | Out-Null
Sql "INSERT INTO financial_entries (entry_date, description, type, amount) VALUES ('2026-10-20', 'Infaq', 'masuk', 500000)" | Out-Null
$before = Sql "SELECT (SELECT COUNT(*) FROM admin_users), (SELECT COUNT(*) FROM financial_entries), latitude, mosque_name FROM mosque_settings"
if ($before -like '1|1|1.257955|Masjid Uji Upgrade') { Pass "data versi lama: $before" } else { Fail "data versi lama: $before" }

# Sesi lama berjalan: python.exe/mpg123 di folder program sedang dipakai saat upgrade.
Start-Process -FilePath "$Root\python\pythonw.exe" -ArgumentList "`"$Root\app\packaging\windows\session.pyw`" --tanpa-layar" -WorkingDirectory "$Root\app"
Start-Sleep -Seconds 15
$running = @(Get-CimInstance Win32_Process -Filter "Name='python.exe' OR Name='pythonw.exe'" | Where-Object { $_.ExecutablePath -like "$Root*" }).Count
if ($running -ge 1) { Pass "sesi lama berjalan ($running proses) saat upgrade" } else { Fail 'sesi lama tidak berjalan' }

$code = Install $New "$env:TEMP\setup-new.log"
if ($code -eq 0) { Pass 'versi baru terpasang di atasnya tanpa error' } else { Fail "installer baru exit $code"; Get-Content "$env:TEMP\setup-new.log" -Tail 30 }
$B = Base
if ((Get-Service JamSholatApache -ErrorAction SilentlyContinue).Status -eq 'Running') { Pass 'Apache berjalan lagi' } else { Fail 'Apache tidak berjalan setelah upgrade' }

$after = Sql "SELECT (SELECT COUNT(*) FROM admin_users), (SELECT COUNT(*) FROM financial_entries), latitude, mosque_name FROM mosque_settings"
if ($after -eq $before) { Pass "akun, kas, lokasi, dan nama tetap ($after)" } else { Fail "data berubah: $before -> $after" }
if ((Sql 'SELECT location_confirmed FROM mosque_settings') -eq '1') { Pass 'lokasi lama dianggap sudah diatur' } else { Fail 'pengingat lokasi muncul di database lama' }

$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$t = Csrf $s "$B/admin/login.php"
$r = Invoke-WebRequest -UseBasicParsing -Uri "$B/admin/login.php" -WebSession $s -Method Post -Body @{ csrf_token = $t; username = 'takmir'; password = 'rahasia123' }
if ($r.Content -match 'Assalamu') { Pass 'login dengan kata sandi lama tetap bisa' } else { Fail 'login gagal setelah upgrade' }
$version = (Get-Content "$Root\app\VERSION" -TotalCount 1).Trim()
$about = Invoke-WebRequest -UseBasicParsing -Uri "$B/admin/about.php" -WebSession $s
if ($about.Content -match [regex]::Escape("Versi $version")) { Pass "halaman Tentang: Versi $version" } else { Fail "halaman Tentang tanpa versi $version" }
foreach ($page in 'help', 'phone', 'murottal') {
    $code = (Invoke-WebRequest -UseBasicParsing -Uri "$B/admin/$page.php" -WebSession $s).StatusCode
    if ($code -eq 200) { Pass "halaman baru $page.php terbuka" } else { Fail "$page.php HTTP $code" }
}
$iq = Get-Item "$Root\app\public\assets\audio\iqomah.mp3" -ErrorAction SilentlyContinue
if ($iq -and $iq.Length -lt 600KB) { Pass "iqomah baru terpasang ($([math]::Round($iq.Length / 1KB)) KB)" } else { Fail "iqomah: $($iq.Length) byte" }

if (-not (Test-Path "$Root\app\public\admin\setup.php")) { Pass 'setup.php lama terhapus saat upgrade' } else { Fail 'setup.php lama masih ada' }
# Reset ke bawaan (#41): akun lama "takmir" menjadi admin / 000, data tetap.
& "$Root\php\php.exe" "$Root\app\scripts\reset_password_cli.php" --ya | Out-Null
$acc = Sql 'SELECT username, must_change_password FROM admin_users'
if ($acc -eq 'admin|1') { Pass 'reset: akun lama menjadi admin / 000, wajib ganti' } else { Fail "reset akun lama: $acc" }
if ((Sql 'SELECT COUNT(*) FROM financial_entries') -eq '1') { Pass 'reset tidak menghapus data kas' } else { Fail 'data kas berubah setelah reset' }

if ($script:Fail -eq 0) { Write-Host 'SEMUA LULUS (upgrade Windows)' } else { Write-Host 'ADA YANG GAGAL (upgrade Windows)'; exit 1 }
