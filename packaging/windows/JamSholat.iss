; JamSholat.iss — installer Windows (Inno Setup 6). Dibangun oleh build.ps1:
;   iscc /DAppVersion=1.1.0 /DStage=<folder staging> /O<folder keluaran> JamSholat.iss
; Isi staging: app\ (kode + audio), apache\, php\, python\, mpg123\, redist\vc_redist.x64.exe
; Lihat DECISIONS.md #39.

#ifndef AppVersion
  #define AppVersion "1.1.0"
#endif
#ifndef Stage
  #define Stage "stage"
#endif

[Setup]
AppId={{6E3B7C2A-4F1D-4B8E-9A61-2D5C7F0B9E14}
AppName=Jam Sholat Masjid
AppVersion={#AppVersion}
AppPublisher=Tim Capstone Ilmu Komputer UGM
AppSupportURL=https://github.com/ghinayasmin/jam-sholat-masjid
DefaultDirName={autopf}\JamSholat
DefaultGroupName=Jam Sholat Masjid
DisableProgramGroupPage=yes
OutputBaseFilename=JamSholat-Setup-{#AppVersion}
Compression=lzma2/fast
SolidCompression=no
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
PrivilegesRequired=admin
WizardStyle=modern
SetupIconFile=..\..\public\favicon.ico
UninstallDisplayIcon={app}\app\public\favicon.ico
UninstallDisplayName=Jam Sholat Masjid
SetupLogging=yes

[Languages]
Name: "id"; MessagesFile: "Indonesian.isl"

[Files]
Source: "{#Stage}\app\*"; DestDir: "{app}\app"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "{#Stage}\apache\*"; DestDir: "{app}\apache"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "{#Stage}\php\*"; DestDir: "{app}\php"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "{#Stage}\python\*"; DestDir: "{app}\python"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "{#Stage}\mpg123\*"; DestDir: "{app}\mpg123"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "{#Stage}\redist\vc_redist.x64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall

[Icons]
; Sesi (alarm, kamera, layar jamaah) mulai otomatis setiap kali pengguna masuk Windows.
Name: "{commonstartup}\Layar Jamaah"; Filename: "{app}\python\pythonw.exe"; Parameters: """{app}\app\packaging\windows\session.pyw"""; WorkingDir: "{app}\app"; IconFilename: "{app}\app\public\favicon.ico"
Name: "{group}\Layar Jamaah"; Filename: "{app}\python\pythonw.exe"; Parameters: """{app}\app\packaging\windows\session.pyw"""; WorkingDir: "{app}\app"; IconFilename: "{app}\app\public\favicon.ico"
Name: "{group}\Panel Takmir"; Filename: "{app}\panel.url"; IconFilename: "{app}\app\public\favicon.ico"
Name: "{group}\Reset kata sandi"; Filename: "{app}\app\packaging\windows\reset-password.cmd"
Name: "{group}\Folder data (database, cadangan, log)"; Filename: "{commonappdata}\JamSholat"
Name: "{group}\Hapus Jam Sholat Masjid"; Filename: "{uninstallexe}"

[InstallDelete]
; Nama pintasan Startup di v1.1.0.
Type: files; Name: "{commonstartup}\Jam Sholat Masjid.lnk"

[Run]
Filename: "{tmp}\vc_redist.x64.exe"; Parameters: "/install /quiet /norestart"; StatusMsg: "Memasang Microsoft Visual C++ Runtime..."; Flags: waituntilterminated
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\app\packaging\windows\setup.ps1"" -Root ""{app}"" -Data ""{commonappdata}\JamSholat"""; StatusMsg: "Menyiapkan Apache, PHP, dan database..."; Flags: runhidden waituntilterminated
; Suara adzan langsung aktif; layar jamaah belum dibuka supaya panel (kata sandi, langkah
; awal) tidak tertutup layar penuh. Layar dibuka dari Start > Layar Jamaah.
Filename: "{app}\python\pythonw.exe"; Parameters: """{app}\app\packaging\windows\session.pyw"" --tanpa-layar"; WorkingDir: "{app}\app"; Description: "Nyalakan suara adzan sekarang"; Flags: postinstall nowait skipifsilent
Filename: "{app}\panel.url"; Description: "Buka panel takmir"; Flags: postinstall shellexec skipifsilent

[Code]
// Upgrade di atas versi lama (#40): Apache dan sesi alarm/kamera lama memakai file di
// folder program, jadi hentikan dulu sebelum file ditimpa. setup.ps1 menyalakan
// Apache lagi; sesi menyala lagi dari halaman akhir installer atau saat login.
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
begin
  Result := '';
  Exec('powershell.exe',
    '-NoProfile -ExecutionPolicy Bypass -Command "Stop-Service JamSholatApache -Force -ErrorAction SilentlyContinue; ' +
    'Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -and $_.ExecutablePath.StartsWith(''' +
    ExpandConstant('{app}') + ''', ''OrdinalIgnoreCase'') } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"',
    '', SW_HIDE, ewWaitUntilTerminated, Code);
end;

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\app\packaging\windows\uninstall.ps1"" -Root ""{app}"""; Flags: runhidden waituntilterminated; RunOnceId: "JamSholatStop"

[UninstallDelete]
Type: files; Name: "{app}\panel.url"
Type: files; Name: "{app}\display.url"
Type: filesandordirs; Name: "{app}\app\config\config.php"
Type: filesandordirs; Name: "{app}\php\php.ini"

[Messages]
id.FinishedLabel=Jam Sholat Masjid sudah terpasang.%n%n1. Masuk ke panel takmir dengan nama pengguna admin dan kata sandi 000, lalu buat kata sandi baru.%n2. Buka layar jamaah dari Start > Layar Jamaah. Tutup dengan Alt+F4; suara adzan tetap berjalan.%n%nSetelah ini layar jamaah dan suara adzan menyala sendiri setiap kali Windows dinyalakan.
