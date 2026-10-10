<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tallas vigentes de indumentaria de un colaborador.
 */
class UniformSize extends Model
{
    use BelongsToTenant;

    public const SHIRT_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
    public const PANTS_SIZES = ['26', '28', '30', '32', '34', '36', '38', '40', '42', '44', '46', '48'];
    public const SHOE_SIZES  = ['34', '35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47'];
    public const SHOE_TYPES  = ['safety' => 'Calzado de seguridad', 'formal' => 'Zapatos de vestir', 'sneaker' => 'Zapatillas'];

    protected $fillable = ['tenant_id', 'user_id', 'shirt_size', 'pants_size', 'shoe_size', 'shoe_type', 'notes', 'updated_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return $this->shirt_size && $this->pants_size && $this->shoe_size;
    }

    public function toApi(): array
    {
        return [
            'shirt_size' => $this->shirt_size,
            'pants_size' => $this->pants_size,
            'shoe_size'  => $this->shoe_size,
            'shoe_type'  => $this->shoe_type,
            'notes'      => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
