<?php

namespace App\Modules\Birthdays\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Regalo animado que un compañero envió el día del cumpleaños */
class BirthdayGift extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'sender_id', 'gift_type', 'date'];

    protected $casts = ['date' => 'date:Y-m-d'];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
