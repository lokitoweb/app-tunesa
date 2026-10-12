<?php

namespace App\Modules\Ethics\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Ethics\Models\EthicsCase;
use App\Modules\Ethics\Models\EthicsCaseAttachment;
use App\Modules\Ethics\Models\EthicsCaseMessage;
use App\Modules\Ethics\Notifications\EthicsCaseReceived;
use App\Modules\Integrations\Services\Push\FcmSender;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Canal ético. Lo del denunciante entra sin identidad (sin usuario, IP ni hora
 * exacta); lo del investigador queda auditado con su nombre.
 */
class EthicsService
{
    public const DISK = 'private';

    /** Sin 0/O ni 1/I/L: se dicta y se copia sin confusiones */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * @param  array{category: string, subject: string, body: string, push_channel?: ?string}  $data
     * @param  list<UploadedFile>  $photos
     * @param  User|null  $identifyAs  solo si quien denuncia eligió identificarse
     * @return array{case: EthicsCase, token: string}
     */
    public function report(Tenant $tenant, array $data, array $photos = [], ?User $identifyAs = null): array
    {
        $token = $this->randomGroups(2);

        $case = DB::transaction(function () use ($tenant, $data, $token, $photos, $identifyAs) {
            $case = EthicsCase::create([
                'tenant_id'    => $tenant->id,
                'public_code'  => $this->uniqueCode(),
                'token_hash'   => hash('sha256', self::normalizeToken($token)),
                'category'     => $data['category'],
                'subject'      => trim($data['subject']),
                'body'         => trim($data['body']),
                'push_channel' => $data['push_channel'] ?? null,
                'reported_on'  => now()->toDateString(),
                'identified'   => $identifyAs !== null,
                'reporter_identity' => $identifyAs ? self::identityOf($identifyAs) : null,
            ]);
            $this->storePhotos($case, null, $photos);

            return $case;
        });

        $this->safely(fn () => $this->investigators($tenant->id)->each(fn (User $u) => $u->notify(new EthicsCaseReceived($case))));

        return ['case' => $case, 'token' => $token];
    }

    /** Caso por código + token; null si no coincide (misma respuesta para ambos casos) */
    public function find(Tenant $tenant, string $code, ?string $token): ?EthicsCase
    {
        $case = EthicsCase::where('tenant_id', $tenant->id)->where('public_code', mb_strtoupper(trim($code)))->first();

        return $case && $case->matchesToken(self::normalizeToken((string) $token)) ? $case : null;
    }

    /** @param  list<UploadedFile>  $photos */
    public function reporterMessage(EthicsCase $case, string $body, array $photos = []): EthicsCaseMessage
    {
        if ($case->isClosed()) {
            throw ValidationException::withMessages(['body' => ['La denuncia está cerrada; si hay algo nuevo, registra una nueva.']]);
        }

        return DB::transaction(function () use ($case, $body, $photos) {
            $message = $case->messages()->create([
                'tenant_id'   => $case->tenant_id,
                'author_type' => EthicsCaseMessage::REPORTER,
                'body'        => trim($body),
                'sent_on'     => now()->toDateString(),
            ]);
            $this->storePhotos($case, $message, $photos);

            $case->investigator_unread = true;
            if ($case->status === 'needs_info') {
                $case->status = 'in_review';
            }
            $case->save();

            return $message;
        });
    }

    public function reply(EthicsCase $case, User $by, string $body, bool $askForInfo = false): EthicsCaseMessage
    {
        $message = DB::transaction(function () use ($case, $by, $body, $askForInfo) {
            $message = $case->messages()->create([
                'tenant_id'       => $case->tenant_id,
                'author_type'     => EthicsCaseMessage::INVESTIGATOR,
                'investigator_id' => $by->id,
                'body'            => trim($body),
                'sent_on'         => now()->toDateString(),
            ]);

            $case->reporter_unread = true;
            if ($askForInfo) {
                $case->status = 'needs_info';
            } elseif ($case->status === 'received') {
                $case->status = 'in_review';
            }
            $case->assigned_to ??= $by->id;
            $case->save();

            return $message;
        });

        app(AuditLogger::class)->log('ethics.replied', $case, ['code' => $case->public_code]);
        $this->pushToReporter($case);

        return $message;
    }

    public function changeStatus(EthicsCase $case, User $by, string $status, ?string $resolution = null): void
    {
        $from = $case->status;
        $case->status          = $status;
        $case->assigned_to   ??= $by->id;
        $case->closed_at       = $status === 'closed' ? now() : null;
        $case->resolution      = $status === 'closed' ? trim((string) $resolution) : $case->resolution;
        $case->reporter_unread = true;
        $case->save();

        app(AuditLogger::class)->log('ethics.status_changed', $case, [
            'code' => $case->public_code, 'from' => EthicsCase::STATUSES[$from] ?? $from, 'to' => $case->statusLabel(),
        ]);
        $this->pushToReporter($case);
    }

    /**
     * Identidad de quien eligió identificarse. La lectura queda en la bitácora
     * y solo la hace quien tiene ethics.view_identity.
     */
    public function revealIdentity(EthicsCase $case, User $by): ?array
    {
        if (! $case->identified || ! $by->can('ethics.view_identity')) {
            return null;
        }
        app(AuditLogger::class)->log('ethics.identity_viewed', $case, ['code' => $case->public_code]);

        return $case->reporter_identity;
    }

    /** @return array<string, mixed> */
    public static function identityOf(User $user): array
    {
        return array_filter([
            'user_id'         => $user->id,
            'name'            => $user->full_name,
            'employee_code'   => $user->employee_code,
            'document'        => trim(strtoupper((string) $user->document_type) . ' ' . $user->document_number) ?: null,
            'email'           => $user->email,
            'phone'           => $user->phone,
            'position'        => $user->position,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public static function normalizeToken(string $token): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($token));
    }

    /** Usuarios de la empresa que reciben el aviso de una denuncia nueva */
    public function investigators(int $tenantId)
    {
        return User::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'active')
            ->whereIn('role', ['company_admin', 'hr_manager'])->get()
            ->filter(fn (User $u) => $u->hasPermission('ethics.investigate'))->values();
    }

    /** Sin texto del caso: el aviso puede verse en la pantalla bloqueada */
    private function pushToReporter(EthicsCase $case): void
    {
        if (! $case->push_channel) {
            return;
        }
        $this->safely(fn () => app(FcmSender::class)->sendToTopic(
            Tenant::find($case->tenant_id),
            'ethics_' . $case->push_channel,
            'Canal ético',
            'Hay novedades en una denuncia que registraste.',
            ['type' => 'ethics.update'],
        ));
    }

    /** @param  list<UploadedFile>  $photos */
    private function storePhotos(EthicsCase $case, ?EthicsCaseMessage $message, array $photos): void
    {
        foreach ($photos as $photo) {
            $jpeg = self::stripMetadata($photo);
            $path = "ethics/{$case->tenant_id}/" . Str::random(40) . '.jpg';
            Storage::disk(self::DISK)->put($path, $jpeg);

            EthicsCaseAttachment::create([
                'tenant_id'  => $case->tenant_id,
                'case_id'    => $case->id,
                'message_id' => $message?->id,
                'path'       => $path,
                'mime'       => 'image/jpeg',
                'size'       => strlen($jpeg),
            ]);
        }
    }

    /**
     * Re-codifica la foto a JPEG: se pierden EXIF (GPS, modelo del celular,
     * fecha) y cualquier otro metadato. La orientación se aplica antes.
     */
    public static function stripMetadata(UploadedFile $photo): string
    {
        $raw   = (string) file_get_contents($photo->getRealPath());
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw ValidationException::withMessages(['photos' => ['No se pudo leer una de las fotos.']]);
        }

        if (function_exists('exif_read_data') && str_starts_with($raw, "\xFF\xD8")) {
            $orientation = (int) (@exif_read_data($photo->getRealPath())['Orientation'] ?? 1);
            $image = match ($orientation) {
                3       => imagerotate($image, 180, 0),
                6       => imagerotate($image, -90, 0),
                8       => imagerotate($image, 90, 0),
                default => $image,
            };
        }

        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** Código de seguimiento: 6 dígitos al azar (fácil de anotar o dictar) */
    private function uniqueCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (EthicsCase::withoutGlobalScopes()->where('public_code', $code)->exists());

        return $code;
    }

    /**
     * Clave: grupos de caracteres aleatorios separados por guion (2×4 ≈ 40 bits).
     * Junto con el código y el límite de intentos por minuto, nadie la adivina.
     */
    private function randomGroups(int $groups, int $size = 4): string
    {
        $parts = [];
        for ($g = 0; $g < $groups; $g++) {
            $part = '';
            for ($i = 0; $i < $size; $i++) {
                $part .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $parts[] = $part;
        }

        return implode('-', $parts);
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
