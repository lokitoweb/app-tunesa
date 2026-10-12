<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de eliminación de cuenta y datos personales (ver AccountDeletion).
 *
 * @property int|null $tenant_id
 * @property int|null $user_id
 * @property string   $email
 * @property string   $source  app | web
 * @property string   $status  pending | completed | rejected
 */
class AccountDeletionRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'email', 'reason', 'source', 'status', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
