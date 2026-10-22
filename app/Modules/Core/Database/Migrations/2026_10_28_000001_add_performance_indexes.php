<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para las búsquedas frecuentes que aún recorrían la tabla:
 * bandeja de justificaciones, búsqueda por DNI, bitácora por usuario,
 * reintentos de webhooks, limpieza de tokens vencidos y casos éticos por
 * empresa. Cada índice se crea solo si no existe (instalaciones que ya
 * lo agregaron a mano no fallan).
 */
return new class extends Migration
{
    private const INDEXES = [
        'attendances'            => ['att_tenant_justif_date_idx' => ['tenant_id', 'justification_status', 'work_date']],
        'users'                  => ['users_tenant_document_idx' => ['tenant_id', 'document_number']],
        'audit_logs'             => ['audit_tenant_user_date_idx' => ['tenant_id', 'user_id', 'created_at']],
        'webhook_deliveries'     => ['webhook_tenant_status_idx' => ['tenant_id', 'status']],
        'personal_access_tokens' => ['pat_expires_at_idx' => ['expires_at']],
        'employee_documents'     => ['emp_docs_tenant_expiry_idx' => ['tenant_id', 'expires_at']],
        'ethics_case_messages'   => ['ethics_msgs_tenant_case_idx' => ['tenant_id', 'case_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (! $this->hasColumns($table, $columns) || Schema::hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
