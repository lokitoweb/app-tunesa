<?php

namespace App\Modules\Announcements\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Concerns\HasAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Comunicado de RR. HH. a un público (toda la empresa, sede, área, cargo o persona) */
class Announcement extends Model
{
    use BelongsToTenant, HasAudience, SoftDeletes;

    public const CATEGORIES = [
        'general'  => ['Aviso general', 'megaphone'],
        'meeting'  => ['Reunión', 'people'],
        'update'   => ['Actualización', 'arrow-repeat'],
        'reminder' => ['Recordatorio', 'bell'],
        'urgent'   => ['Urgente', 'exclamation-triangle'],
    ];

    public const STATUSES = ['draft' => 'Borrador', 'scheduled' => 'Programado', 'sent' => 'Enviado'];

    protected $fillable = [
        'tenant_id', 'title', 'body', 'category', 'is_important', 'event_at', 'location', 'url',
        'status', 'scheduled_at', 'sent_at', 'recipients', 'created_by',
    ];

    protected $casts = [
        'is_important' => 'boolean',
        'event_at'     => 'datetime',
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
        'recipients'   => 'integer',
    ];

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category][0] ?? $this->category;
    }
}
