<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales que administra RRHH (la app no restablece contraseñas):
 *  - tenant_settings.login_email_domain: dominio del correo de acceso
 *    [DNI]@dominio que se genera al dar de alta (NULL = heredar).
 *  - users.temp_password_expires_at: la contraseña temporal vence; pasado ese
 *    momento el colaborador no entra hasta que RRHH le restablezca el acceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->string('login_email_domain', 150)->nullable()->after('address');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('temp_password_expires_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', fn (Blueprint $table) => $table->dropColumn('login_email_domain'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('temp_password_expires_at'));
    }
};
