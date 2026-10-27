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

# Pintasan Startup bernama lama (v1.1.0) harus dibersihkan installer baru.
$Startup = "$env:ProgramData\Microsoft\Windows\Start Menu\Programs\StartUp"
New-Item -ItemType File -Force "$Startup\Jam Sholat Masjid.lnk" | Out-Null

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

# Akun bawaan admin / 000 (DECISIONS.md #41): wajib ganti kata sandi sebelum panel terbuka.
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
function Login([string]$pw) {
    $p = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/login.php"
    $t = [regex]::Match($p.Content, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
    Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/login.php" -Method Post -Body @{ csrf_token = $t; username = 'admin'; password = $pw }
}
$page = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/"
if ($page.Content -match 'Masuk untuk mengatur') { Pass 'panel mengarah ke halaman masuk' } else { Fail 'panel tidak ke halaman masuk' }
$r = Login '000'
if ($r.Content -match 'Ganti kata sandi bawaan') { Pass 'admin / 000 masuk, diarahkan ke ganti kata sandi bawaan' } else { Fail 'akun bawaan admin / 000' }
$r = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/settings.php"
if ($r.Content -match 'Ganti kata sandi bawaan') { Pass 'panel terkunci sebelum kata sandi bawaan diganti' } else { Fail 'panel tidak terkunci' }
$token = [regex]::Match($r.Content, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
$form = @{ csrf_token = $token; new_password = 'rahasia123'; confirm_password = 'rahasia123' }
$null = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/change_password.php" -Method Post -Body $form
$dash = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/dashboard.php"
if ($dash.Content -match "Assalamu") { Pass 'kata sandi bawaan diganti, panel takmir terbuka' } else { Fail 'panel tidak terbuka' }
$cities = Invoke-WebRequest -UseBasicParsing -Uri "$B/assets/data/kabupaten_kota.json"
if ($cities.Content -match 'Kabupaten Sleman') { Pass 'daftar kabupaten/kota untuk pilihan lokasi tersedia' } else { Fail 'kabupaten_kota.json' }
$w = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/welcome.php"
if ($w.Content -match 'Langkah 1 dari 3') { Pass 'halaman Langkah awal terbuka' } else { Fail 'Langkah awal' }

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
$out = (& "$Root\php\php.exe" "$Root\app\scripts\reset_password_cli.php" --ya) -join "`n"
if ($out -match 'nama pengguna admin dan kata sandi 000') { Pass 'reset kata sandi kembali ke admin / 000' } else { Fail "reset kata sandi CLI: $out" }
$r = Invoke-WebRequest -UseBasicParsing -WebSession $s -Uri "$B/admin/dashboard.php"
if ($r.Content -match 'Masuk untuk mengatur') { Pass 'sesi lama keluar setelah reset' } else { Fail 'sesi lama tetap masuk setelah reset' }
if ((Login 'rahasia123').Content -match 'Nama pengguna atau kata sandi salah') { Pass 'kata sandi lama ditolak setelah reset' } else { Fail 'kata sandi lama masih berlaku' }
if ((Login '000').Content -match 'Ganti kata sandi bawaan') { Pass 'admin / 000 berlaku lagi, wajib ganti' } else { Fail 'reset ke bawaan' }
$lnk = Get-ChildItem "$env:ProgramData\Microsoft\Windows\Start Menu\Programs" -Recurse -Filter 'Reset kata sandi.lnk' -ErrorAction SilentlyContinue
if ($lnk) { Pass 'pintasan Start > Reset kata sandi ada' } else { Fail 'pintasan Reset kata sandi tidak ada' }
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
# Lanjut murottal (#40): mpg123 bawaan harus bisa melompat ke frame tertentu (-k), uji
# tanpa suara (-t) pada 10 frame setelah detik ke-30 Al-Fatihah.
$fat = Get-ChildItem "$Root\app\public\assets\audio\murottal\001_*.mp3" | Select-Object -First 1
& "$Root\mpg123\mpg123.exe" -q -t -k 1148 -n 10 $fat.FullName 2>&1 | Out-Null
if ($LASTEXITCODE -eq 0) { Pass 'mpg123 bisa melanjutkan murottal dari detik tertentu (-k)' } else { Fail "mpg123 -k exit $LASTEXITCODE" }
# Penjaga volume (#40): kode C# di skrip harus terkompilasi; runner tanpa speaker
# menjawab "tidak bisa dicek (tidak ada speaker aktif)".
$vol = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$Root\app\packaging\windows\ensure-volume.ps1" 2>&1 | Out-String
if ($LASTEXITCODE -eq 0 -and $vol -match '^(normal|suara|tidak bisa dicek \(tidak ada speaker aktif\))') { Pass "penjaga volume: $($vol.Trim())" } else { Fail "penjaga volume: $vol" }
if (Test-Path "$Startup\Layar Jamaah.lnk") { Pass 'pintasan Startup "Layar Jamaah" terpasang' } else { Fail 'pintasan Startup tidak ada' }
if (-not (Test-Path "$Startup\Jam Sholat Masjid.lnk")) { Pass 'pintasan Startup lama (v1.1.0) dihapus' } else { Fail 'pintasan Startup lama masih ada (sesi ganda)' }
$shell = New-Object -ComObject WScript.Shell
foreach ($name in 'Layar Jamaah', 'Panel Takmir') {
    $lnk = $shell.CreateShortcut("$env:ProgramData\Microsoft\Windows\Start Menu\Programs\Jam Sholat Masjid\$name.lnk")
    if ($lnk.IconLocation -match 'favicon\.ico') { Pass "pintasan $name memakai ikon aplikasi" } else { Fail "ikon pintasan $name`: $($lnk.IconLocation)" }
}

# Alur layar jamaah (uji manual no. 2 dan 19): sesi kedua tidak menggandakan layanan,
# tetapi membuka lagi layar yang ditutup (Alt+F4) atau membawanya ke depan.
Add-Type @'
using System; using System.Runtime.InteropServices;
public static class Fg {
    [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);
    public static uint Pid() { uint p; GetWindowThreadProcessId(GetForegroundWindow(), out p); return p; }
    [DllImport("user32.dll")] public static extern bool SystemParametersInfo(uint a, uint b, IntPtr c, uint d);
}
'@
# Klik di Start memberi proses baru izin tampil di depan; Start-Process dari skrip uji
# tidak. Kunci fokus dimatikan supaya uji meniru klik sungguhan.
[Fg]::SystemParametersInfo(0x2001, 0, [IntPtr]::Zero, 2) | Out-Null  # SPI_SETFOREGROUNDLOCKTIMEOUT = 0
function Get-Kiosk { Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -match 'edge-kiosk' } }
function Get-KioskMain { @(Get-Kiosk | Where-Object { $_.CommandLine -notmatch '--type=' }) }
function Get-Session { Get-CimInstance Win32_Process -Filter "Name='pythonw.exe'" | Where-Object { $_.CommandLine -match 'session\.pyw' } }
function Get-Alarm { @(Get-CimInstance Win32_Process -Filter "Name='python.exe'" | Where-Object { $_.CommandLine -match 'alarm_service' }) }
function Click-LayarJamaah { Start-Process -FilePath "$Root\python\pythonw.exe" -ArgumentList "`"$Root\app\packaging\windows\session.pyw`"" -WorkingDirectory "$Root\app" }

# Instalasi pertama (halaman akhir installer): suara aktif, layar belum dibuka.
Start-Process -FilePath "$Root\python\pythonw.exe" -ArgumentList "`"$Root\app\packaging\windows\session.pyw`" --tanpa-layar" -WorkingDirectory "$Root\app"
Start-Sleep -Seconds 20
if ((Get-Alarm).Count -eq 1 -and -not (Get-Kiosk)) { Pass 'instalasi pertama: alarm jalan, layar belum menutupi panel' } else { Fail "instalasi pertama: alarm $((Get-Alarm).Count), kiosk $(@(Get-Kiosk).Count)" }
if ((Get-Content "$Data\logs\alarm.log" -Raw -ErrorAction SilentlyContinue) -notmatch 'Traceback') { Pass 'alarm.log tanpa error' } else { Fail 'alarm.log berisi Traceback' }

Click-LayarJamaah; Start-Sleep -Seconds 10
$kiosk = Get-KioskMain
if ($kiosk.Count -eq 1) { Pass 'Start > Layar Jamaah membuka layar' } else { Fail "jendela kiosk: $($kiosk.Count)" }
if ($kiosk -and $kiosk[0].CommandLine -match 'layar=desktop') { Pass 'layar dibuka dengan petunjuk Alt+F4 (?layar=desktop)' } else { Fail 'URL kiosk tanpa layar=desktop' }

# Tertutup jendela lain (Alt+Tab): klik membawa layar ke depan, tanpa jendela kedua.
$note = Start-Process notepad -PassThru; Start-Sleep -Seconds 3
if ([Fg]::Pid() -eq $note.Id) {
    Click-LayarJamaah; Start-Sleep -Seconds 6
    if ([Fg]::Pid() -eq $kiosk[0].ProcessId) { Pass 'klik membawa layar ke depan jendela lain' } else { Fail "jendela depan: PID $([Fg]::Pid())" }
} else {
    Click-LayarJamaah; Start-Sleep -Seconds 6
    Write-Host '  · lewati cek jendela depan: runner tanpa desktop interaktif'
}
Stop-Process -Id $note.Id -Force -ErrorAction SilentlyContinue
if ((Get-KioskMain).Count -eq 1) { Pass 'klik saat layar terbuka tidak membuka layar kedua' } else { Fail "jendela kiosk: $((Get-KioskMain).Count)" }

Get-Kiosk | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 3
Click-LayarJamaah; Start-Sleep -Seconds 10
if ((Get-KioskMain).Count -eq 1) { Pass 'layar yang ditutup (Alt+F4) terbuka lagi dari shortcut' } else { Fail 'shortcut tidak membuka lagi layar' }
if (@(Get-Session).Count -eq 1 -and (Get-Alarm).Count -eq 1) { Pass 'sesi & alarm tetap satu setelah 4 klik (suara tidak dobel)' } else { Fail "sesi $(@(Get-Session).Count), alarm $((Get-Alarm).Count)" }

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
