<?php

namespace App\Services\Attendance;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda las selfies de marcación en el disco privado (ATTENDANCE_DISK).
 *
 * JPEG y PNG se convierten a WebP (calidad 80, lado mayor máx. 1280 px,
 * respetando la orientación EXIF) con GD si la extensión trae imagewebp().
 * Si GD no tiene WebP se guarda el original y se avisa en el log una sola vez.
 * Un WebP subido se guarda tal cual.
 *
 * Ruta: photos/{tenant}/{user}/{tipo}/{YYYY}/{MM}/{YmdHis}_{user}_{rand}.{ext}
 */
class SelfieStorage
{
    public const MAX_SIDE = 1280;

    public const QUALITY = 80;

    public static function webpSupported(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromstring');
    }

    public function disk(): string
    {
        return config('filesystems.attendance_disk', 'private');
    }

    public function store(UploadedFile $photo, int $tenantId, int $userId, string $type): string
    {
        $now       = now();
        $directory = "photos/{$tenantId}/{$userId}/{$type}/{$now->format('Y')}/{$now->format('m')}";
        $basename  = $now->format('YmdHis') . "_{$userId}_" . Str::lower(Str::random(6));
        $mime      = strtolower((string) $photo->getMimeType());

        if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            if (self::webpSupported()) {
                $webp = $this->toWebp($photo->getRealPath(), $mime);
                if ($webp !== null) {
                    $path = "{$directory}/{$basename}.webp";
                    Storage::disk($this->disk())->put($path, $webp);

                    return $path;
                }
            } else {
                $this->warnNoWebpOnce();
            }
        }

        $extension = match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        return $photo->storeAs($directory, "{$basename}.{$extension}", $this->disk());
    }

    /** Convierte a WebP y reduce a MAX_SIDE. null si GD no puede leer la imagen. */
    public function toWebp(string $file, string $mime): ?string
    {
        $raw   = @file_get_contents($file);
        $image = $raw !== false ? @imagecreatefromstring($raw) : false;
        if (! $image) {
            return null;
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyExifOrientation($image, $file);
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $long   = max($width, $height);

        if ($long > self::MAX_SIDE) {
            $ratio  = self::MAX_SIDE / $long;
            $scaled = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), IMG_BICUBIC);
            if ($scaled) {
                imagedestroy($image);
                $image = $scaled;
            }
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $ok   = imagewebp($image, null, self::QUALITY);
        $data = ob_get_clean();
        imagedestroy($image);

        return $ok && $data ? $data : null;
    }

    /** Las fotos del teléfono suelen venir rotadas por EXIF: se gira para guardarla derecha */
    private function applyExifOrientation(\GdImage $image, string $file): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($file)['Orientation'] ?? 1);
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private function warnNoWebpOnce(): void
    {
        if (Cache::add('selfie_storage:no_webp_warned', true, now()->addDay())) {
            Log::warning('GD no tiene soporte WebP (imagewebp): las selfies se guardan en su formato original.');
        }
    }
}
