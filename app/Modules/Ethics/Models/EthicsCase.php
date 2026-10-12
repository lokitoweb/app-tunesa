<?php

namespace App\Modules\Ethics\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Denuncia del canal ético. No tiene relación con quien la hizo: se consulta
 * con el código público y el token de seguimiento (solo su hash queda aquí).
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
        'discrimination'      => 'Discriminación',
        'fraud'               => 'Fraude, robo o corrupción',
        'safety'              => 'Seguridad y salud en el trabajo',
        'conflict_of_interest' => 'Conflicto de intereses',
        'abuse_of_authority'  => 'Abuso de autoridad',
        'other'               => 'Otro',
    ];

    protected $fillable = [
        'tenant_id', 'public_code', 'token_hash', 'category', 'subject', 'body', 'status', 'push_channel',
        'reported_on', 'reporter_unread', 'investigator_unread', 'assigned_to', 'closed_at', 'resolution',
    ];

    protected $hidden = ['token_hash', 'push_channel'];

    protected $casts = [
        'subject'             => 'encrypted',
        'body'                => 'encrypted',
        'resolution'          => 'encrypted',
        'reported_on'         => 'date',
        'closed_at'           => 'datetime',
        'reporter_unread'     => 'boolean',
        'investigator_unread' => 'boolean',
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
