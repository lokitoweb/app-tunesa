<?php

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Toda empresa debe tener secreto de webhook (las nuevas lo reciben al
 * crearse): se genera para las que no lo tienen. Pasa por el modelo para
 * que quede cifrado con APP_KEY. No se revierte: borrar secretos dejaría
 * los webhooks sin firma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenant::withTrashed()->select('id', 'slug')->orderBy('id')->each(
            fn (Tenant $tenant) => TenantSetting::ensureWebhookSecret($tenant)
        );
    }

    public function down(): void
    {
        // Sin cambios: los secretos generados se conservan
    }
};
