<#
  uninstall.ps1 — dijalankan uninstaller sebelum file dihapus. Data di
  C:\ProgramData\JamSholat (database, poster, foto, cadangan) tidak dihapus.
#>
param([Parameter(Mandatory = $true)][string]$Root)
$ErrorActionPreference = 'Continue'
$Service = 'JamSholatApache'
if (Get-Service $Service -ErrorAction SilentlyContinue) {
    Stop-Service $Service -Force -ErrorAction SilentlyContinue
    & (Join-Path $Root 'apache\bin\httpd.exe') -k uninstall -n $Service
}
& netsh advfirewall firewall delete rule name="Jam Sholat Masjid (HTTP)" | Out-Null
# Hentikan sesi (alarm, kamera) yang masih berjalan dari folder program ini.
Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -and $_.ExecutablePath.StartsWith($Root, 'OrdinalIgnoreCase') } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
