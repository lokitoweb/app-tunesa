<?php

namespace App\Modules\Onboarding\Models;

use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incorporación de un colaborador. 'approved' libera la marcación
 * (users.onboarding_status lleva una copia para el candado).
 *
 * pending → (completa etapas) → submitted → approved | rejected → submitted ...
 */
class OnboardingProcess extends Model
{
    use BelongsToTenant, BelongsToBranchScope;

    public const BRANCH_SCOPE = [null, 'user_id'];

    public const PENDING   = 'pending';
    public const SUBMITTED = 'submitted';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';

    public const STATUSES = [self::PENDING, self::SUBMITTED, self::APPROVED, self::REJECTED];

    protected $fillable = [
        'tenant_id', 'user_id', 'status', 'submitted_at', 'reviewed_by', 'approved_at', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
    ];

    protected $attributes = ['status' => self::PENDING];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
