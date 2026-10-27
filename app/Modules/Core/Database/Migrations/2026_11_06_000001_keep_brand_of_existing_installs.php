<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * El sistema pasa a ser neutro: el nombre, los logos, el ícono y el dominio de acceso por defecto
 * ya no traen una marca escrita en el código (salen de APP_NAME / APP_DOMAIN y de "Marca y app").
 *
 * En una instalación que ya estaba en uso (hay usuarios) se guarda como marca PROPIA de la
 * plataforma lo que hasta ahora se veía por defecto, solo en los campos que nadie personalizó:
 * se sigue viendo exactamente igual. En una instalación nueva (base vacía) no hace nada.
 */
return new class extends Migration
{
    private const LEGACY = [
        'trade_name'         => 'Grupo Negreiros',
        'login_email_domain' => 'turismonegreiros.com',
    ];

    public function up(): void
    {
        if (! DB::table('users')->exists()) {
            return;
        }

        $row = DB::table('tenant_settings')->whereNull('tenant_id')->first();
        if (! $row) {
            DB::table('tenant_settings')->insert(['tenant_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('tenant_settings')->whereNull('tenant_id')->first();
        }

        $changes = [];
        foreach (self::LEGACY as $column => $value) {
            if (blank($row->{$column} ?? null)) {
                $changes[$column] = $value;
            }
        }

        // Los archivos de la marca anterior pasan al almacenamiento público, como si se hubieran subido
        $files = ['logo_path' => 'logo.png', 'logo_dark_path' => 'logo-white.png', 'favicon_path' => 'favicon.ico'];
        foreach ($files as $column => $file) {
            $source = __DIR__ . '/legacy-brand/' . $file;
            if (blank($row->{$column} ?? null) && is_file($source)) {
                $path = 'branding/platform/' . $file;
                Storage::disk('public')->put($path, file_get_contents($source));
                $changes[$column] = $path;
            }
        }

        if ($changes) {
            DB::table('tenant_settings')->where('id', $row->id)->update($changes + ['updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás automática: la marca se cambia o se quita desde "Marca y app"
    }
};
