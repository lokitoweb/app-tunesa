<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales de servicios externos por empresa (Migo, Nubefact, SMTP), en la misma
 * tabla de configuración que ya cifra webhook, Google Maps y Firebase: fila de la
 * plataforma (tenant_id NULL) por defecto y una fila por empresa que la puede pisar.
 * Los secretos (tokens y clave SMTP) se guardan cifrados con el cast 'encrypted'.
 *
 * Sin valor guardado se usan los del .env (config/services.php): nada cambia al migrar.
 * Solo agrega columnas: compatible hacia atrás y sin bloquear la tabla.
 */
return new class extends Migration
{
    private const SECRET_TEXT = ['migo_api_token', 'nubefact_token', 'smtp_password'];

    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->text('migo_api_token')->nullable();
            $table->string('migo_ruc_url', 255)->nullable();
            $table->string('migo_dni_url', 255)->nullable();
            $table->string('nubefact_url', 255)->nullable()->comment('Ruta de la API de Nubefact (demo o producción)');
            $table->text('nubefact_token')->nullable();
            $table->string('smtp_host', 150)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->nullable()->comment('tls | ssl');
            $table->string('smtp_username', 150)->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('mail_from_address', 150)->nullable();
            $table->string('mail_from_name', 150)->nullable();
        });

    }
    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn([...self::SECRET_TEXT, 'migo_ruc_url', 'migo_dni_url', 'nubefact_url', 'smtp_host', 'smtp_port',
                'smtp_encryption', 'smtp_username', 'mail_from_address', 'mail_from_name']);
        });
    }
};
