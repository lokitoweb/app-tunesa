<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo Payslip (Boleta de Pago)
 * Boletas PDF subidas por RRHH, accesibles desde la app móvil del empleado.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property int    $user_id
 * @property string $status
 */
class Payslip extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'uploaded_by',
        'title',
        'period_month',
        'period_year',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'gross_salary',
        'deductions',
        'net_salary',
        'breakdown',
        'status',
        'published_at',
        'read_at',
        'notes',
    ];

    protected $casts = [
        'published_at'  => 'datetime',
        'read_at'       => 'datetime',
        'breakdown'     => 'array',
        'gross_salary'  => 'decimal:2',
        'deductions'    => 'decimal:2',
        'net_salary'    => 'decimal:2',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Marca la boleta como leída por el empleado */
    public function markAsRead(): void
    {
        if (is_null($this->read_at)) {
            $this->update(['read_at' => now()]);
        }
    }

    /** Indica si la boleta es visible para el empleado */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** Etiqueta del período: "Octubre 2024" */
    public function getPeriodLabelAttribute(): string
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo',
            4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
            7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre',
            10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return ($months[$this->period_month] ?? '') . ' ' . $this->period_year;
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
