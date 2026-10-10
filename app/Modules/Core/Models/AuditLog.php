<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de acciones sensibles (solo inserción). Ver App\Modules\Core\Services\AuditLogger.
 */
class AuditLog extends Model
{
    use BelongsToTenant, BelongsToBranchScope;

    /** Alcance por sede: un usuario de zona o sede solo ve lo que hicieron sus colaboradores */
    public const BRANCH_SCOPE = [null, 'user_id'];

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'user_id', 'action', 'subject_type', 'subject_id', 'ip', 'user_agent', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo
    {
        // Quien actuó puede ser el Super Admin (sin empresa) o un usuario ya dado de baja
        return $this->belongsTo(User::class)->withoutGlobalScope(TenantScope::class)->withTrashed();
    }
}
