<?php

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * URLs temporales para archivos privados (legajo, selfies de marcación).
 *
 * - Disco S3 (o compatible): URL prefirmada del propio bucket.
 * - Disco local: ruta firmada de Laravel (/secure-files/{kind}/{id}) que
 *   vence a los N minutos; sin la firma válida responde 403.
 *
 * La URL se entrega solo después de verificar permisos en el controlador,
 * así que quien la recibe ya estaba autorizado a ver el archivo.
 */
class SecureFileUrl
{
    public const KINDS = ['document', 'selfie-in', 'selfie-out', 'punch-photo'];

    /** Miniaturas de selfie: siempre por la ruta firmada (si falta, sirve la foto completa) */
    public const THUMB_KINDS = ['selfie-in-thumb', 'selfie-out-thumb', 'punch-photo-thumb'];

    public function make(string $disk, string $path, string $kind, int $id, ?int $minutes = null): string
    {
        $expires = now()->addMinutes($minutes ?? (int) config('filesystems.signed_url_minutes', 5));

        if (config("filesystems.disks.{$disk}.driver") === 's3') {
            return Storage::disk($disk)->temporaryUrl($path, $expires);
        }

        return URL::temporarySignedRoute('secure-files.show', $expires, ['kind' => $kind, 'id' => $id]);
    }

    /**
     * Miniatura de 160 px de una selfie ('selfie-in', 'selfie-out' o 'punch-photo'). El vencimiento se
     * redondea a ventanas de $minutes: durante toda la ventana el enlace es el mismo, así el navegador
     * reutiliza la imagen en recargas y filtros (SecureFileController la deja guardar hasta que vence)
     * y una vista con muchas selfies no agota el límite de pedidos. Vale entre $minutes y 2×$minutes.
     */
    public function thumb(string $kind, int $id, ?int $minutes = null): string
    {
        $window = 60 * max(1, $minutes ?? (int) config('filesystems.signed_url_minutes', 5));
        $expires = \Illuminate\Support\Carbon::createFromTimestamp((intdiv(now()->getTimestamp(), $window) + 2) * $window);

        return URL::temporarySignedRoute('secure-files.show', $expires, ['kind' => "{$kind}-thumb", 'id' => $id]);
    }
}
