<?php

namespace App\Modules\Ethics\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Denuncia del canal ético. No tiene relación con quien la hizo: se consulta
 * con el código de seguimiento (6 dígitos) y la clave (solo su hash queda aquí).
 * Las enviadas desde la app guardan los datos de quien denuncia cifrados en
 * reporter_identity y solo RRHH puede verlos (queda en la bitácora).
 *
 * received → in_review ⇄ needs_info → closed
 */
class EthicsCase extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    public const STATUSES = [
        'received'   => 'Recibida',
        'in_review'  => 'En investigación',
        'needs_info' => 'Necesita más información',
        'closed'     => 'Cerrada',
    ];

    public const CATEGORIES = [
        'harassment'          => 'Acoso laboral o sexual',
        'hostility'           => 'Hostigamiento',
        'mistreatment'        => 'Maltrato',
        'theft'               => 'Robo',
        'discrimination'      => 'Discriminación',
        'fraud'               => 'Fraude o corrupción',
        'safety'              => 'Seguridad y salud en el trabajo',
        'conflict_of_interest' => 'Conflicto de intereses',
        'abuse_of_authority'  => 'Abuso de autoridad',
        'other'               => 'Otro',
    ];

    protected $fillable = [
        'tenant_id', 'public_code', 'token_hash', 'category', 'subject', 'body', 'status', 'push_channel',
        'reported_on', 'reporter_unread', 'investigator_unread', 'assigned_to', 'closed_at', 'resolution',
        'identified', 'reporter_identity',
    ];

    protected $hidden = ['token_hash', 'push_channel', 'reporter_identity'];

    protected $casts = [
        'subject'             => 'encrypted',
        'body'                => 'encrypted',
        'resolution'          => 'encrypted',
        'reported_on'         => 'date',
        'closed_at'           => 'datetime',
        'reporter_unread'     => 'boolean',
        'investigator_unread' => 'boolean',
        'identified'          => 'boolean',
        // Denuncias desde la app: identidad cifrada y sin user_id en
        // columnas consultables. La ve quien tiene ethics.view_identity (RRHH), auditado.
        'reporter_identity'   => 'encrypted:array',
    ];

    protected $attributes = ['status' => 'received'];

    public function messages(): HasMany
    {
        return $this->hasMany(EthicsCaseMessage::class, 'case_id')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EthicsCaseAttachment::class, 'case_id')->orderBy('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function matchesToken(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->token_hash, hash('sha256', $token));
    }

    public function categoryIcon(): string
    {
        return match($this->category) {
            'harassment'           => 'shield-slash',
            'hostility'            => 'exclamation-diamond',
            'mistreatment'         => 'emoji-frown',
            'theft'                => 'cash-stack',
            'discrimination'       => 'slash-circle',
            'fraud'                => 'bank',
            'safety'               => 'heart-pulse',
            'conflict_of_interest' => 'arrow-left-right',
            'abuse_of_authority'   => 'person-slash',
            default                => 'shield-exclamation',
        };
    }

    public function categoryTone(): string
    {
        return match($this->category) {
            'harassment', 'theft', 'fraud' => 'danger',
            'hostility', 'discrimination', 'abuse_of_authority' => 'warning',
            'safety' => 'info',
            'mistreatment' => 'danger',
            default => 'secondary',
        };
    }

    public function isFromApp(): bool
    {
        return (bool) $this->identified;
    }

    public function isAnonymousWeb(): bool
    {
        return ! $this->identified;
    }

    public function originLabel(): string
    {
        return $this->identified ? 'App móvil (Identidad reservada)' : 'Web anónima (Sin rastro)';
    }

    public function tenantName(): string
    {
        return $this->tenant?->company_name ?? 'Empresa';
    }

    public function statusTone(): string
    {
        return match($this->status) {
            'received'   => 'primary',
            'in_review'  => 'warning',
            'needs_info' => 'info',
            'closed'     => 'success',
            default      => 'secondary',
        };
    }

    public function statusIcon(): string
    {
        return match($this->status) {
            'received'   => 'inbox-fill',
            'in_review'  => 'search',
            'needs_info' => 'question-circle-fill',
            'closed'     => 'check-circle-fill',
            default      => 'circle',
        };
    }
}
