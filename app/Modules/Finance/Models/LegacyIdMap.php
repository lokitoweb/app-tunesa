<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** id del ERP de escritorio -> registro de la web (re-importar no duplica) */
class LegacyIdMap extends Model
{
    use BelongsToTenant;

    protected $table = 'legacy_id_map';

    protected $fillable = ['tenant_id', 'legacy_table', 'legacy_id', 'model_type', 'model_id'];
}
