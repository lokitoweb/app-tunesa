<?php

namespace App\Modules\Birthdays\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Saludo de cumpleaños ya enviado (uno por persona y año) */
class BirthdayGreeting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'year', 'colleagues_notified'];
}
