<?php

namespace App\Modules\Attendance\Services\Attendance;

/** Selfie ya guardada por SelfieStorage: ruta en el disco privado y huella SHA-256 del archivo */
final class StoredSelfie
{
    public function __construct(
        public readonly string $path,
        public readonly string $sha256,
    ) {}

    public function thumbPath(): string
    {
        return SelfieStorage::thumbPathFor($this->path);
    }
}
