<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Política de privacidad administrable (Marca y app › Políticas de la app). NULL = hereda de la
 * plataforma o, si tampoco hay, la predeterminada (resources/legal/privacy.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->text('privacy_policy')->nullable()->comment('Política de privacidad en Markdown sencillo');
            $table->date('privacy_policy_updated_on')->nullable()->comment('Última vez que cambió el texto de la política');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['privacy_policy', 'privacy_policy_updated_on']);
        });
    }
};
