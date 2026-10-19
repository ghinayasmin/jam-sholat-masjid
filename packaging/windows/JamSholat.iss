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
Name: "{commonstartup}\Jam Sholat Masjid"; Filename: "{app}\python\pythonw.exe"; Parameters: """{app}\app\packaging\windows\session.pyw"""; WorkingDir: "{app}\app"
Name: "{group}\Layar Jamaah"; Filename: "{app}\python\pythonw.exe"; Parameters: """{app}\app\packaging\windows\session.pyw"""; WorkingDir: "{app}\app"
Name: "{group}\Panel Takmir"; Filename: "{app}\panel.url"
Name: "{group}\Reset kata sandi"; Filename: "{app}\app\packaging\windows\reset-password.cmd"
Name: "{group}\Folder data (database, cadangan, log)"; Filename: "{commonappdata}\JamSholat"
Name: "{group}\Hapus Jam Sholat Masjid"; Filename: "{uninstallexe}"

[Run]
Filename: "{tmp}\vc_redist.x64.exe"; Parameters: "/install /quiet /norestart"; StatusMsg: "Memasang Microsoft Visual C++ Runtime..."; Flags: waituntilterminated
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\app\packaging\windows\setup.ps1"" -Root ""{app}"" -Data ""{commonappdata}\JamSholat"""; StatusMsg: "Menyiapkan Apache, PHP, dan database..."; Flags: runhidden waituntilterminated
Filename: "{app}\python\pythonw.exe"; Parameters: """{app}\app\packaging\windows\session.pyw"""; WorkingDir: "{app}\app"; Description: "Jalankan layar jamaah sekarang"; Flags: postinstall nowait skipifsilent
Filename: "{app}\panel.url"; Description: "Buka panel takmir untuk membuat akun"; Flags: postinstall shellexec skipifsilent

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\app\packaging\windows\uninstall.ps1"" -Root ""{app}"""; Flags: runhidden waituntilterminated; RunOnceId: "JamSholatStop"

[UninstallDelete]
Type: files; Name: "{app}\panel.url"
Type: files; Name: "{app}\display.url"
Type: filesandordirs; Name: "{app}\app\config\config.php"
Type: filesandordirs; Name: "{app}\php\php.ini"

[Messages]
id.FinishedLabel=Jam Sholat Masjid sudah terpasang. Buka panel takmir untuk membuat akun, lalu atur lokasi masjid. Layar jamaah dan suara adzan berjalan otomatis setiap kali Windows dinyalakan dan pengguna masuk.
