<?php

namespace App\Modules\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validación estricta de comprobantes y adjuntos (PDF e imágenes), común a todo el
 * sistema: legajo, permisos, justificaciones, boletas, vouchers, documentos y
 * capacitaciones. Complementa a 'mimes' (que sigue fijando tipos y tamaño):
 *
 *  - Tipo real por contenido (finfo), no por extensión ni por lo que diga el navegador.
 *  - PDF: cabecera %PDF- (magic number) y sin contenido activo: JavaScript, acciones de
 *    lanzar programas, archivos incrustados ni multimedia. Un comprobante no los necesita
 *    y son el vector habitual de PDFs maliciosos.
 *  - Imagen: debe decodificarse como imagen (getimagesize).
 *
 * ponytail: el contenido activo dentro de streams comprimidos no se descomprime para
 * revisarlo; agregar un sanitizador (qpdf/ghostscript) si se exige ese nivel.
 */
final class SafeUpload implements ValidationRule
{
    public const PDF = 'application/pdf';
    public const IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Marcas de contenido activo en un PDF (nombres de objeto con su barra) */
    private const ACTIVE_PDF = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/RichMedia', '/SubmitForm', '/ImportData'];

    /** @param  list<string>  $allowed  tipos MIME reales permitidos */
    public function __construct(private readonly array $allowed = [self::PDF, ...self::IMAGES]) {}

    public static function pdf(): self
    {
        return new self([self::PDF]);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && ($error = self::inspect($value, $this->allowed))) {
            $fail($error);
        }
    }

    /** Mensaje de error, o null si el archivo es seguro */
    public static function inspect(UploadedFile $file, array $allowed = [self::PDF, ...self::IMAGES]): ?string
    {
        $path = $file->getRealPath();
        if (! $path || ! is_readable($path)) {
            return 'No se pudo leer el archivo. Vuelve a adjuntarlo.';
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if (! in_array($mime, $allowed, true)) {
            return in_array(self::PDF, $allowed, true) && count($allowed) === 1
                ? 'El archivo no es un PDF válido.'
                : 'El contenido del archivo no es un PDF o una imagen válida.';
        }

        if ($mime === self::PDF) {
            $raw = (string) file_get_contents($path);
            if (! preg_match('/^\s*%PDF-\d\.\d/', substr($raw, 0, 1024))) {
                return 'El archivo no es un PDF válido.';
            }
            foreach (self::ACTIVE_PDF as $marker) {
                // "/JS" no debe confundirse con "/JSFoo": se exige un delimitador después del nombre
                if (preg_match('#' . preg_quote($marker, '#') . '(?![A-Za-z0-9])#', $raw)) {
                    return 'El PDF contiene contenido activo (scripts, archivos incrustados o acciones) y no se acepta. Guárdalo de nuevo como PDF simple o imprímelo a PDF.';
                }
            }
        } elseif (@getimagesize($path) === false) {
            return 'La imagen está dañada o no es una imagen válida.';
        }

        return null;
    }
}
