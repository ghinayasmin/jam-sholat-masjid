<#
  test-windows.ps1 — uji asap installer di Windows bersih (GitHub Actions).
    pwsh packaging\windows\test-windows.ps1 -Installer dist\JamSholat-Setup-1.1.0.exe
  Memasang tanpa jendela, lalu memeriksa Apache, PHP, database, halaman setup, perintah
  reset kata sandi, layanan alarm, dan uninstall. Keluar dengan kode ≠ 0 bila ada yang gagal.
#>
param([Parameter(Mandatory = $true)][string]$Installer)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$script:Fail = 0
function Pass($m) { Write-Host "  ✓ $m" }
function Fail($m) { Write-Host "  ✗ $m"; $script:Fail = 1 }

$os = Get-CimInstance Win32_OperatingSystem
Write-Host "== $($os.Caption) ($($os.Version)) =="
$Root = "$env:ProgramFiles\JamSholat"
$Data = "$env:ProgramData\JamSholat"

$p = Start-Process -FilePath $Installer -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', "/LOG=$env:TEMP\jamsholat-setup.log" -Wait -PassThru
if ($p.ExitCode -eq 0) { Pass 'installer selesai tanpa error' } else { Fail "installer exit $($p.ExitCode)"; Get-Content "$env:TEMP\jamsholat-setup.log" -Tail 40 }

$Port = (Get-Content "$Data\port.txt" -ErrorAction SilentlyContinue | Select-Object -First 1)
if (-not $Port) { $Port = 80 }
$B = if ($Port -eq '80') { 'http://localhost' } else { "http://localhost:$Port" }
Write-Host "  · Apache memakai port $Port"

if ((Get-Service JamSholatApache -ErrorAction SilentlyContinue).Status -eq 'Running') { Pass 'layanan Apache berjalan' } else {
    Fail 'layanan Apache tidak berjalan'
    Get-Content "$Data\logs\setup.log" -Tail 40 -ErrorAction SilentlyContinue
    Get-Content "$Data\logs\apache-error.log" -Tail 20 -ErrorAction SilentlyContinue
    Get-NetTCPConnection -LocalPort 80 -State Listen -ErrorAction SilentlyContinue |
        ForEach-Object { "  port 80 dipakai PID $($_.OwningProcess) $((Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue).ProcessName)" }
    exit 1   # pemeriksaan berikutnya tidak berarti tanpa Apache
}

try {
    $raw = Invoke-WebRequest -UseBasicParsing -Uri "$B/api/schedule.php" -TimeoutSec 30
    $api = $raw.Content | ConvertFrom-Json -ErrorAction SilentlyContinue
    if ($api.success) { Pass "API jadwal: Subuh $($api.fajr) · Maghrib $($api.maghrib)" } else {
        Fail 'API jadwal tidak valid'
        Write-Host ('  respons: ' + $raw.Content.Substring(0, [Math]::Min(300, $raw.Content.Length)))
        Write-Host ('  byte awal: ' + (($raw.RawContentStream.ToArray() | Select-Object -First 4) -join ' '))
        Get-Content "$Data\logs\php-error.log" -Tail 20 -ErrorAction SilentlyContinue
    }
} catch { Fail "API jadwal: $($_.Exception.Message)"; Get-Content "$Data\logs\apache-error.log" -Tail 20 -ErrorAction SilentlyContinue; Get-Content "$Data\logs\php-error.log" -Tail 20 -ErrorAction SilentlyContinue }

$r = Invoke-WebRequest -UseBasicParsing -Uri "$B/display.php"
if ($r.StatusCode -eq 200) { Pass 'layar jamaah HTTP 200' } else { Fail "layar jamaah HTTP $($r.StatusCode)" }

# Akun pertama lewat halaman setup (dari localhost).
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$page = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/"
if ($page.BaseResponse.ResponseUri -match 'setup\.php' -or $page.Content -match 'Buat akun takmir') { Pass 'panel mengarah ke pembuatan akun pertama' } else { Fail 'panel tidak ke setup' }
$token = [regex]::Match($page.Content, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
$form = @{ csrf_token = $token; username = 'takmir'; password = 'rahasia123'; confirm_password = 'rahasia123' }
$done = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/setup.php" -Method Post -Body $form
if ($done.Content -match 'recovery-code') { Pass 'akun takmir dibuat + kode pemulihan tampil' } else { Fail 'setup akun gagal' }
$dash = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/dashboard.php"
if ($dash.Content -match "Assalamu") { Pass 'panel takmir terbuka setelah login' } else { Fail 'panel tidak terbuka' }

# Unggah foto (GD + batas 20 MB) lewat profil masjid.
Add-Type -AssemblyName System.Drawing
$bmp = New-Object System.Drawing.Bitmap 2400, 1350
$img = "$env:TEMP\masjid-test.jpg"; $bmp.Save($img, [System.Drawing.Imaging.ImageFormat]::Jpeg); $bmp.Dispose()
$prof = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/mosque_profile.php"
$token = [regex]::Match($prof.Content, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
$res = & curl.exe -s -o NUL -w '%{http_code}' -b "jamsholat_admin=$($s.Cookies.GetCookies($B)['jamsholat_admin'].Value)" `
    -F "csrf_token=$token" -F 'mosque_name=Masjid Uji' -F 'mosque_address=' -F "mosque_photo=@$img;type=image/jpeg" "$B/admin/mosque_profile.php"
$photo = Get-ChildItem "$Data\uploads\publications" -Filter 'masjid_*.jpg' -ErrorAction SilentlyContinue | Select-Object -First 1
if ($res -eq '302' -and $photo) { Pass "unggah foto masjid tersimpan ($([math]::Round($photo.Length / 1KB)) KB)" } else { Fail "unggah foto (HTTP $res)" }
$u = Invoke-WebRequest -UseBasicParsing -Uri "$B/uploads/publications/$($photo.Name)"
if ($u.StatusCode -eq 200) { Pass 'foto bisa dibuka layar jamaah (/uploads)' } else { Fail 'alias /uploads' }

# Perintah teknisi & audio.
$out = & "$Root\php\php.exe" "$Root\app\scripts\reset_password_cli.php" --kode takmir
if ($out -match 'Kode pemulihan baru') { Pass 'reset kata sandi (php CLI) berjalan' } else { Fail 'reset kata sandi CLI' }
$n = (Get-ChildItem "$Root\app\public\assets\audio\murottal" -Filter '*.mp3').Count
if ($n -ge 114) { Pass "audio: adzan, iqomah, $n surah murottal" } else { Fail "murottal hanya $n file" }
$mp = & "$Root\mpg123\mpg123.exe" --version 2>&1
if ($mp -match 'mpg123') { Pass "mpg123: $($mp | Select-Object -First 1)" } else { Fail 'mpg123 tidak jalan' }

# Layanan alarm & kamera: modul bisa dimuat dengan Python yang dibundel.
$env:JAMSHOLAT_DB_PATH = "$Data\storage\jamsholat.db"
$env:JAMSHOLAT_AUDIO_PATH = "$Root\app\public\assets\audio"
$env:JAMSHOLAT_PHP_BIN = "$Root\php\php.exe"
$alarm = Start-Process -FilePath "$Root\python\python.exe" -ArgumentList "`"$Root\app\alarm_service\alarm_service.py`"" `
    -RedirectStandardError "$env:TEMP\alarm.err" -RedirectStandardOutput "$env:TEMP\alarm.out" -PassThru -WindowStyle Hidden
Start-Sleep -Seconds 10
$log = (Get-Content "$env:TEMP\alarm.err", "$env:TEMP\alarm.out" -ErrorAction SilentlyContinue) -join "`n"
if (-not $alarm.HasExited -and $log -match 'Memulai layanan alarm' -and $log -notmatch 'Traceback') { Pass 'layanan alarm berjalan tanpa error' } else { Fail 'layanan alarm'; Write-Host $log }
Stop-Process -Id $alarm.Id -Force -ErrorAction SilentlyContinue
$cv = & "$Root\python\python.exe" -c "import cv2, numpy, sqlite3; print(cv2.__version__)" 2>&1
if ($LASTEXITCODE -eq 0) { Pass "OpenCV $cv untuk kamera" } else { Fail "OpenCV: $cv" }
if (Test-Path "$env:ProgramData\Microsoft\Windows\Start Menu\Programs\StartUp\Jam Sholat Masjid.lnk") { Pass 'pintasan Startup terpasang' } else { Fail 'pintasan Startup tidak ada' }
$lnk = (New-Object -ComObject WScript.Shell).CreateShortcut("$env:ProgramData\Microsoft\Windows\Start Menu\Programs\Jam Sholat Masjid\Layar Jamaah.lnk")
if ($lnk.IconLocation -match 'favicon\.ico') { Pass 'pintasan Layar Jamaah memakai ikon aplikasi' } else { Fail "ikon pintasan: $($lnk.IconLocation)" }

# Shortcut Layar Jamaah: sesi kedua tidak menggandakan layanan, tapi membuka lagi
# layar yang sudah ditutup (Alt+F4) — uji manual no. 2 dan 19.
function Get-Kiosk { Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -match 'edge-kiosk' } }
function Get-Session { Get-CimInstance Win32_Process -Filter "Name='pythonw.exe'" | Where-Object { $_.CommandLine -match 'session\.pyw' } }
$session = "`"$Root\app\packaging\windows\session.pyw`""
Start-Process -FilePath "$Root\python\pythonw.exe" -ArgumentList $session -WorkingDirectory "$Root\app"
Start-Sleep -Seconds 20
if (Get-Kiosk) { Pass 'sesi membuka layar jamaah (Edge kiosk)' } else { Fail 'layar jamaah tidak terbuka' }
Get-Kiosk | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 3
Start-Process -FilePath "$Root\python\pythonw.exe" -ArgumentList $session -WorkingDirectory "$Root\app"
Start-Sleep -Seconds 10
if (Get-Kiosk) { Pass 'shortcut membuka lagi layar yang ditutup' } else { Fail 'shortcut tidak membuka lagi layar' }
Start-Process -FilePath "$Root\python\pythonw.exe" -ArgumentList $session -WorkingDirectory "$Root\app"
Start-Sleep -Seconds 8
$main = @(Get-Kiosk | Where-Object { $_.CommandLine -notmatch '--type=' }).Count
if ($main -eq 1) { Pass 'klik saat layar terbuka tidak membuka layar kedua' } else { Fail "jumlah jendela Edge kiosk: $main" }
$n = @(Get-Session).Count
if ($n -eq 1) { Pass 'sesi tetap satu (suara tidak dobel)' } else { Fail "jumlah sesi: $n" }
Get-Kiosk | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Get-Session | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Get-CimInstance Win32_Process -Filter "Name='python.exe'" | Where-Object { $_.CommandLine -match 'alarm_service|motion_detect' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

# Uninstall: layanan & firewall hilang, data tetap.
$un = Start-Process -FilePath "$Root\unins000.exe" -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART' -Wait -PassThru
Start-Sleep -Seconds 3
if (-not (Get-Service JamSholatApache -ErrorAction SilentlyContinue)) { Pass 'uninstall menghapus layanan Apache' } else { Fail 'layanan Apache masih ada' }
if (Test-Path "$Data\storage\jamsholat.db") { Pass 'uninstall: database tetap ada' } else { Fail 'uninstall menghapus database' }

if ($script:Fail -eq 0) { Write-Host "SEMUA LULUS ($($os.Caption))" } else { Write-Host "ADA YANG GAGAL ($($os.Caption))"; exit 1 }
