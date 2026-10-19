<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Menyimpan foto unggahan (poster, foto masjid) untuk layar jamaah.
 *
 * Foto HP biasanya 12 MP (4000×3000). Chromium di Pi 3 mendekode gambar lewat
 * CPU, jadi foto sebesar itu membuat pergantian slide tersendat. Foto diperkecil
 * agar sisi terpanjangnya paling banyak MAX_SIDE piksel, diputar sesuai EXIF,
 * lalu disimpan sebagai JPEG (DECISIONS.md #29).
 */
final class UploadedImage
{
    public const MAX_SIDE = 1920;
    /** Batas resolusi yang mau didekode: 40 MP ≈ 160 MB memori di GD. */
    private const MAX_PIXELS = 40_000_000;

    /**
     * Validasi lalu simpan $tmpPath ke $dir dengan nama "<prefix>_<acak>.jpg".
     * Mengembalikan [nama file, null] bila berhasil, atau [null, pesan error].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function store(array $file, string $dir, string $prefix, int $maxBytes): array
    {
        $maxBytes = self::maxBytes($maxBytes);
        $maxMb = round($maxBytes / 1024 / 1024);
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return [null, "Ukuran foto melebihi batas perangkat. Pilih foto yang lebih kecil dari $maxMb MB."];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [null, 'Foto gagal diunggah. Coba lagi.'];
        }
        if ($file['size'] > $maxBytes) {
            return [null, "Ukuran foto melebihi $maxMb MB."];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return [null, 'Format foto tidak didukung. Gunakan JPG atau PNG.'];
        }
        $size = @getimagesize($file['tmp_name']);
        if ($size === false) {
            return [null, 'File ini bukan foto yang bisa dibaca. Coba foto lain.'];
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            return [null, 'Resolusi foto terlalu besar. Kecilkan dulu fotonya, misalnya lewat screenshot.'];
        }

        $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.jpg';
        $target = $dir . '/' . $name;
        if (self::resizeToJpeg($file['tmp_name'], $mime, $target)) {
            @unlink($file['tmp_name']);
            return [$name, null];
        }

        // GD tidak tersedia atau gagal: simpan file asli apa adanya.
        $name = $prefix . '_' . bin2hex(random_bytes(8)) . ($mime === 'image/png' ? '.png' : '.jpg');
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            return [null, 'Foto gagal disimpan di perangkat.'];
        }
        return [$name, null];
    }

    /** Batas unggahan yang benar-benar berlaku, untuk ditampilkan di formulir. */
    public static function maxBytes(int $configured): int
    {
        return min($configured, self::iniBytes((string) ini_get('upload_max_filesize')) ?: $configured);
    }

    /** "20M" → 20971520. */
    private static function iniBytes(string $value): int
    {
        $n = (int) $value;
        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }

    private static function resizeToJpeg(string $source, string $mime, string $target): bool
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return false;
        }
        $img = $mime === 'image/png' ? @imagecreatefrompng($source) : @imagecreatefromjpeg($source);
        if ($img === false) {
            return false;
        }

        // Foto HP menyimpan arah putar di EXIF; tanpa ini foto tegak tampil miring.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($angle !== 0) {
                $rotated = imagerotate($img, $angle, 0);
                if ($rotated !== false) {
                    imagedestroy($img);
                    $img = $rotated;
                }
            }
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($nw, $nh);
        // PNG transparan diberi latar putih (JPEG tidak punya transparansi).
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        $ok = imagejpeg($out, $target, 85);
        imagedestroy($out);
        if ($ok) {
            @chmod($target, 0664);
        }
        return $ok;
    }
}
