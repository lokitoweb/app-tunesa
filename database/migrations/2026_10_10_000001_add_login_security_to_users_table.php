<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login endurecido: bloqueo temporal por intentos fallidos, rastro del último
 * acceso y cambio de contraseña obligatorio para cuentas creadas por RRHH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('password');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->boolean('must_change_password')->default(false)->after('last_login_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip', 'must_change_password']);
        });
    }
};
