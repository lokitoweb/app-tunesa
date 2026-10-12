<?php

namespace App\Modules\Ethics\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Mensaje de la conversación de una denuncia: del denunciante (sin id) o de un investigador */
class EthicsCaseMessage extends Model
{
    use BelongsToTenant;

    public const REPORTER     = 'reporter';
    public const INVESTIGATOR = 'investigator';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'case_id', 'author_type', 'investigator_id', 'body', 'sent_on'];

    protected $casts = [
        'body'    => 'encrypted',
        'sent_on' => 'date',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(EthicsCase::class, 'case_id');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EthicsCaseAttachment::class, 'message_id');
    }

    public function fromReporter(): bool
    {
        return $this->author_type === self::REPORTER;
    }
}
