<?php

namespace App\Modules\CompanyDocs\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lectura y confirmación de un documento por una persona */
class CompanyDocumentRead extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'document_id', 'user_id', 'first_read_at', 'last_read_at', 'acknowledged_at', 'acknowledged_ip'];

    protected $casts = [
        'first_read_at'   => 'datetime',
        'last_read_at'    => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
