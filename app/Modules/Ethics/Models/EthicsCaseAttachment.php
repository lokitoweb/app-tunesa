<?php

namespace App\Modules\Ethics\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto adjunta a una denuncia, en disco privado y sin metadatos (se re-codifica al subir) */
class EthicsCaseAttachment extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'case_id', 'message_id', 'path', 'mime', 'size'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(EthicsCase::class, 'case_id');
    }
}
