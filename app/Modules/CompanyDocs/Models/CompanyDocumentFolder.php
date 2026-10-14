<?php

namespace App\Modules\CompanyDocs\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Carpeta de documentos de la empresa (Políticas, Reglamentos...) */
class CompanyDocumentFolder extends Model
{
    use BelongsToTenant;

    /** Carpetas con las que arranca cada empresa: [nombre, descripción, ícono] */
    public const DEFAULTS = [
        ['Políticas', 'Políticas internas de la empresa.', 'shield-check'],
        ['Reglamento Interno de Trabajo', 'RIT y normas de convivencia.', 'journal-text'],
        ['Seguridad y Salud en el Trabajo', 'Plan de SST, recomendaciones y protocolos.', 'heart-pulse'],
        ['Manuales', 'Manuales de procesos y procedimientos.', 'book'],
        ['Declaraciones', 'Formatos y declaraciones juradas.', 'file-earmark-text'],
    ];

    public const ICONS = [
        'folder' => 'Carpeta', 'shield-check' => 'Políticas', 'journal-text' => 'Reglamento', 'heart-pulse' => 'Salud',
        'book' => 'Manual', 'file-earmark-text' => 'Formato', 'clipboard-check' => 'Procedimiento', 'megaphone' => 'Comunicado',
        'mortarboard' => 'Formación', 'building' => 'Empresa', 'people' => 'Personal', 'cash-coin' => 'Pagos',
    ];

    protected $fillable = ['tenant_id', 'name', 'description', 'icon', 'color', 'position'];

    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class, 'folder_id');
    }

    public static function ensureDefaults(): void
    {
        if (! current_tenant_id() || static::exists()) {
            return;
        }
        foreach (self::DEFAULTS as $i => [$name, $description, $icon]) {
            static::create(['name' => $name, 'description' => $description, 'icon' => $icon, 'position' => $i]);
        }
    }
}
