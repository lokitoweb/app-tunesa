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
 * Si quien denuncia elige identificarse, sus datos van cifrados en
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
        // Solo si quien denuncia eligió identificarse: cifrada y sin user_id en
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
}
