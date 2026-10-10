<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El modelo User pasó de App\Models a App\Modules\Core\Models: las columnas
 * polimórficas guardan el nombre de la clase, así que los tokens de Sanctum
 * (sesiones de la app móvil) y las notificaciones existentes deben seguirlo.
 */
return new class extends Migration
{
    private const OLD = 'App\\Models\\User';
    private const NEW = 'App\\Modules\\Core\\Models\\User';

    private const COLUMNS = [
        'personal_access_tokens' => 'tokenable_type',
        'notifications'          => 'notifiable_type',
    ];

    public function up(): void
    {
        $this->rename(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rename(self::NEW, self::OLD);
    }

    private function rename(string $from, string $to): void
    {
        foreach (self::COLUMNS as $table => $column) {
            DB::table($table)->where($column, $from)->update([$column => $to]);
        }
    }
};
