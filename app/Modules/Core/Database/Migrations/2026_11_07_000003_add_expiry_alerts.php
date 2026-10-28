<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vencimientos (RR. HH. › Vencimientos): SOAT y revisión técnica de los vehículos, y a quién y
 * cuándo avisar. Los destinatarios son de cada empresa; el token del bot de Telegram puede ser de
 * la plataforma (un solo bot para todas) o de la empresa, y va cifrado como los demás secretos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('soat_expires_at')->nullable()->after('branch_id');
            $table->date('technical_review_expires_at')->nullable()->after('soat_expires_at');
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->text('telegram_bot_token')->nullable();
            $table->text('expiry_alert_emails')->nullable()->comment('Correos separados por coma');
            $table->text('expiry_alert_telegram_chats')->nullable()->comment('Chat IDs de Telegram separados por coma');
            $table->string('expiry_alert_days', 50)->nullable()->comment('Días antes del vencimiento en que se avisa, ej. 30,15,7,1,0');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn(['soat_expires_at', 'technical_review_expires_at']));
        Schema::table('tenant_settings', fn (Blueprint $table) => $table->dropColumn(['telegram_bot_token', 'expiry_alert_emails', 'expiry_alert_telegram_chats', 'expiry_alert_days']));
    }
};
