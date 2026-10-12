<?php

namespace App\Modules\Attendance\Services\Attendance;

use App\Modules\Attendance\Exceptions\AttendanceException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda las selfies de marcación en el disco privado (ATTENDANCE_DISK).
 *
 * Toda foto se vuelve a codificar con GD, así nunca se guardan los metadatos
 * del teléfono (EXIF con GPS, modelo, hora): WebP (calidad 80) si GD trae
 * imagewebp(), si no JPEG. Lado mayor máx. 1280 px, respetando la orientación
 * EXIF. Junto a cada foto queda una miniatura de 160 px ({nombre}_t.{ext})
 * para los listados, y se devuelve el SHA-256 del archivo guardado (huella de
 * la evidencia). Una foto que GD no puede leer se rechaza (PHOTO_UNREADABLE),
 * salvo un WebP en un servidor cuyo GD no tiene WebP: se guarda como llegó.
 *
 * Ruta: photos/{tenant}/{user}/{tipo}/{YYYY}/{MM}/{YmdHis}_{user}_{rand}.{ext}
 */
class SelfieStorage
{
    public const MAX_SIDE = 1280;

    public const QUALITY = 80;

    public const THUMB_SIDE = 160;

    public const THUMB_QUALITY = 70;

    /** @param  bool|null  $webp  null: según GD (las pruebas fuerzan el respaldo JPEG) */
    public function __construct(private readonly ?bool $webp = null) {}

    public static function webpSupported(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromstring');
    }

    public function disk(): string
    {
        return config('filesystems.attendance_disk', 'private');
    }

    /** Miniatura de una foto guardada: photos/.../x.webp → photos/.../x_t.webp */
    public static function thumbPathFor(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === ''
            ? "{$path}_t"
            : substr($path, 0, -strlen($extension) - 1) . "_t.{$extension}";
    }

    public function store(UploadedFile $photo, int $tenantId, int $userId, string $type): StoredSelfie
    {
        $now       = now();
        $directory = "photos/{$tenantId}/{$userId}/{$type}/{$now->format('Y')}/{$now->format('m')}";
        $basename  = $now->format('YmdHis') . "_{$userId}_" . Str::lower(Str::random(6));
        $mime      = strtolower((string) $photo->getMimeType());

        $webp = $this->webp ?? self::webpSupported();
        if (! $webp) {
            $this->warnNoWebpOnce();
        }

        $encoded = $this->encode($photo->getRealPath(), $mime, $webp);
        if ($encoded === null && $mime === 'image/webp' && ! $webp) {
            // GD sin WebP no puede leer el WebP de la app: se guarda como llegó (sin miniatura)
            $path  = $photo->storeAs($directory, "{$basename}.webp", $this->disk());
            $bytes = Storage::disk($this->disk())->get($path);

            return new StoredSelfie($path, hash('sha256', $bytes));
        }
        if ($encoded === null) {
            throw new AttendanceException('No pudimos leer la foto. Toma la selfie de nuevo.', 'PHOTO_UNREADABLE');
        }

        [$full, $thumb] = $encoded;
        $extension = $webp ? 'webp' : 'jpg';
        $path      = "{$directory}/{$basename}.{$extension}";

        Storage::disk($this->disk())->put($path, $full);
        Storage::disk($this->disk())->put(self::thumbPathFor($path), $thumb);

        return new StoredSelfie($path, hash('sha256', $full));
    }

    /** Borra la foto y su miniatura (sin fallar si ya no están) */
    public function delete(?string $path, ?string $disk = null): void
    {
        if (! $path) {
            return;
        }

        Storage::disk($disk ?? $this->disk())->delete([$path, self::thumbPathFor($path)]);
    }

    /**
     * Vuelve a codificar la foto (sin metadatos) y arma su miniatura.
     *
     * @return array{0: string, 1: string}|null  [foto, miniatura] o null si GD no puede leerla
     */
    public function encode(string $file, string $mime, bool $webp = true): ?array
    {
        $raw   = @file_get_contents($file);
        $image = $raw !== false && $raw !== '' ? @imagecreatefromstring($raw) : false;
        if (! $image) {
            return null;
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyExifOrientation($image, $file);
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $full  = $this->scaled($image, self::MAX_SIDE);
        $thumb = $this->scaled($image, self::THUMB_SIDE);
        imagedestroy($image);

        $data = [$this->output($full, $webp, self::QUALITY), $this->output($thumb, $webp, self::THUMB_QUALITY)];
        imagedestroy($full);
        imagedestroy($thumb);

        return in_array(null, $data, true) ? null : $data;
    }

    /** Compatibilidad: solo la foto en WebP */
    public function toWebp(string $file, string $mime): ?string
    {
        return $this->encode($file, $mime)[0] ?? null;
    }

    /** Copia reducida a $side de lado mayor (nunca agranda) */
    private function scaled(\GdImage $image, int $side): \GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $ratio  = min(1, $side / max($width, $height));
        $w      = max(1, (int) round($width * $ratio));
        $h      = max(1, (int) round($height * $ratio));

        $copy = imagecreatetruecolor($w, $h);
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagecopyresampled($copy, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return $copy;
    }

    private function output(\GdImage $image, bool $webp, int $quality): ?string
    {
        ob_start();
        if ($webp) {
            $ok = imagewebp($image, null, $quality);
        } else {
            // JPEG no tiene transparencia: fondo blanco
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $ok = imagejpeg($flat, null, $quality);
            imagedestroy($flat);
        }
        $data = ob_get_clean();

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
            Log::warning('GD no tiene soporte WebP (imagewebp): las selfies se guardan en JPEG.');
        }
    }
}
