<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen del intento rechazado: "server" (el backend rechazó la marcación) o
 * "app" (la app la bloqueó antes de enviarla: GPS falso, GPS impreciso o fuera
 * del radio en su verificación previa, y la reportó con POST /api/attendance/attempts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punch_attempts', function (Blueprint $table) {
            $table->string('source', 10)->default('server')->after('error_code');
            $table->index(['tenant_id', 'user_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punch_attempts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'user_id', 'client_uuid']);
            $table->dropColumn('source');
        });
    }
};
