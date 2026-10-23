<?php

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Única forma de entregar un archivo privado (adjuntos de permisos, justificaciones,
 * boletas, vouchers, selfies). Quien llama ya validó el acceso (permiso + empresa, o
 * URL firmada); aquí se fija cómo se sirve:
 *  - Cache-Control private/no-store: ningún proxy ni el navegador lo guarda
 *    (response()->file() lo marcaba public por defecto).
 *  - nosniff + CSP sandbox: un PDF o imagen manipulado no ejecuta scripts en el dominio.
 */
final class PrivateFile
{
    public const HEADERS = [
        'Cache-Control'           => 'private, no-store, max-age=0',
        'X-Content-Type-Options'  => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
    ];

    /** 404 si no hay ruta o el archivo no existe en el disco */
    public static function response(string $disk, ?string $path, ?string $name = null, string $disposition = 'inline'): Response
    {
        abort_if(empty($path) || ! Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response($path, $name ?? basename($path), self::HEADERS, $disposition);
    }
}
