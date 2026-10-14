<?php

namespace App\Modules\Benefits\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Categoría de beneficios de una empresa (la crea y ordena RRHH) */
class BenefitCategory extends Model
{
    use BelongsToTenant;

    /** Categorías con las que arranca cada empresa: [nombre, ícono, color] */
    public const DEFAULTS = [
        ['Descuentos', 'tag', '#6C4CE0'],
        ['Salud y bienestar', 'heart-pulse', '#E0457B'],
        ['Alimentación', 'cup-hot', '#F08C2E'],
        ['Educación', 'book', '#2E86DE'],
        ['Entretenimiento', 'film', '#10AC84'],
        ['Financieros', 'piggy-bank', '#0E9F9A'],
    ];

    /** Íconos disponibles (mismos nombres en el panel y en la app) */
    public const ICONS = [
        'gift' => 'Regalo', 'tag' => 'Etiqueta', 'percent' => 'Porcentaje', 'heart-pulse' => 'Salud', 'cup-hot' => 'Comida',
        'book' => 'Educación', 'film' => 'Entretenimiento', 'piggy-bank' => 'Finanzas', 'bag' => 'Compras', 'car-front' => 'Transporte',
        'airplane' => 'Viajes', 'house' => 'Hogar', 'bicycle' => 'Deporte', 'phone' => 'Tecnología', 'people' => 'Familia', 'star' => 'Destacado',
    ];

    protected $fillable = ['tenant_id', 'name', 'icon', 'color', 'position'];

    public function benefits(): HasMany
    {
        return $this->hasMany(Benefit::class, 'category_id');
    }

    /** Crea las categorías iniciales la primera vez que la empresa usa el módulo */
    public static function ensureDefaults(): void
    {
        if (! current_tenant_id() || static::exists()) {
            return;
        }
        foreach (self::DEFAULTS as $i => [$name, $icon, $color]) {
            static::create(['name' => $name, 'icon' => $icon, 'color' => $color, 'position' => $i]);
        }
    }
}
