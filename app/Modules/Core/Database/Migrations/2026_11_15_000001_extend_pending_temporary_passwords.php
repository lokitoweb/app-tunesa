<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una sola vez, al desplegar: los colaboradores ya registrados que aún no cambiaron su contraseña
 * temporal (vencida o no) reciben 10 días desde hoy para entrar y cambiarla (empiezan a usar la app
 * después de su alta). Los que se registren desde ahora siguen con las 72 h de siempre
 * (CredentialProvisioner::TEMP_TTL_HOURS). No toca a quien ya la cambió ni amplía a quien tenga más plazo.
 */
return new class extends Migration
{
    public const DAYS = 10;

    public function up(): void
    {
        $until = now(config('app.timezone'))->addDays(self::DAYS)->endOfDay();

        DB::table('users')
            ->where('must_change_password', true)
            ->whereNotNull('temp_password_expires_at')
            ->where('temp_password_expires_at', '<', $until)
            ->update(['temp_password_expires_at' => $until]);
    }

    public function down(): void
    {
        // Sin vuelta atrás: devolver el plazo anterior dejaría sin acceso a quien aún no entró
    }
};
