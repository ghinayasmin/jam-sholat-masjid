<#
  ensure-volume.ps1 — dipanggil layanan alarm (alarm_service/volume_guard.py) sebelum
  adzan/iqomah: speaker bawaan Windows yang bisu dibunyikan lagi, dan volume di bawah
  -Min dinaikkan ke -RaiseTo. Mencetak satu baris status (DECISIONS.md #40).
#>
param([double]$Min = 0.30, [double]$RaiseTo = 0.70)
$ErrorActionPreference = 'Stop'
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
[ComImport, Guid("5CDF2C82-841E-4546-9722-0CF74078229A"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
interface IAudioEndpointVolume {
    int f(); int g(); int h(); int i();
    int SetMasterVolumeLevelScalar(float level, Guid ctx);
    int j();
    int GetMasterVolumeLevelScalar(out float level);
    int k(); int l(); int m(); int n();
    int SetMute([MarshalAs(UnmanagedType.Bool)] bool mute, Guid ctx);
    int GetMute(out bool mute);
}
[ComImport, Guid("D666063F-1587-4E43-81F1-B948E807363F"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
interface IMMDevice { int Activate(ref Guid id, int ctx, IntPtr p, out IAudioEndpointVolume v); }
[ComImport, Guid("A95664D2-9614-4F35-A746-DE8DB63617E6"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
interface IMMDeviceEnumerator { int f(); int GetDefaultAudioEndpoint(int flow, int role, out IMMDevice dev); }
[ComImport, Guid("BCDE0395-E52F-467C-8E3D-C4579291692E")] class MMDeviceEnumerator { }
public static class JamSholatVolume {
    static IAudioEndpointVolume Endpoint() {
        var en = (IMMDeviceEnumerator)new MMDeviceEnumerator();
        IMMDevice dev;
        Marshal.ThrowExceptionForHR(en.GetDefaultAudioEndpoint(0, 1, out dev));
        IAudioEndpointVolume v;
        var iid = typeof(IAudioEndpointVolume).GUID;
        Marshal.ThrowExceptionForHR(dev.Activate(ref iid, 23, IntPtr.Zero, out v));
        return v;
    }
    public static float Get() { float l; Marshal.ThrowExceptionForHR(Endpoint().GetMasterVolumeLevelScalar(out l)); return l; }
    public static bool Muted() { bool m; Marshal.ThrowExceptionForHR(Endpoint().GetMute(out m)); return m; }
    public static void Set(float l) { Marshal.ThrowExceptionForHR(Endpoint().SetMasterVolumeLevelScalar(l, Guid.Empty)); }
    public static void Unmute() { Marshal.ThrowExceptionForHR(Endpoint().SetMute(false, Guid.Empty)); }
}
'@
try {
    $level = [JamSholatVolume]::Get()
    $muted = [JamSholatVolume]::Muted()
} catch {
    Write-Output "tidak bisa dicek (tidak ada speaker aktif)"
    exit 0
}
$parts = @()
if ($muted) { [JamSholatVolume]::Unmute(); $parts += 'dibunyikan lagi' }
if ($level -lt $Min) {
    [JamSholatVolume]::Set([float]$RaiseTo)
    $parts += ('dinaikkan {0}% ke {1}%' -f [math]::Round($level * 100), [math]::Round($RaiseTo * 100))
}
if ($parts.Count -eq 0) { Write-Output ('normal ({0}%)' -f [math]::Round($level * 100)) }
else { Write-Output ('suara ' + ($parts -join ' dan ')) }
