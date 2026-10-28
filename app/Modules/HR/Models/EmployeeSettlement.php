<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cese registrado con la liquidación tal como se calculó ese día (se reimprime igual) */
class EmployeeSettlement extends Model
{
    use BelongsToTenant;

    public const REASONS = [
        'resignation'       => 'Renuncia voluntaria',
        'contract_end'      => 'Fin de contrato',
        'mutual_agreement'  => 'Mutuo disenso',
        'trial_period'      => 'No superó el periodo de prueba',
        'arbitrary_dismissal' => 'Despido arbitrario',
        'just_cause'        => 'Despido por falta grave',
        'retirement'        => 'Jubilación',
        'death'             => 'Fallecimiento',
        'other'             => 'Otro',
    ];

    protected $fillable = ['tenant_id', 'user_id', 'labor_regime', 'termination_date', 'reason', 'notes', 'data', 'total', 'created_by'];

    protected $casts = ['termination_date' => 'date', 'data' => 'array', 'total' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
