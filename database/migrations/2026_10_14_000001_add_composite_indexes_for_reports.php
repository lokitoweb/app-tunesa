<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices compuestos para reportes, tablero en vivo y listados grandes.
 *
 * Defensiva: solo crea un índice si la tabla y todas sus columnas existen y si
 * ningún índice existente ya lo cubre (mismas columnas como prefijo izquierdo).
 * Ya existían (y por eso se omiten): attendances (tenant_id, user_id, work_date),
 * (tenant_id, branch_id, work_date), (tenant_id, status, work_date);
 * leave_requests (tenant_id, user_id, start_date); payslips (tenant_id, user_id,
 * period_year, period_month); audit_logs (tenant_id, action, created_at);
 * notifications (notifiable_type, notifiable_id, read_at); employee_documents
 * (tenant_id, status); holidays único (tenant_id, date).
 *
 * No se agrega un único (tenant_id, user_id, work_date) en attendances: ya existe
 * unique_attendance_per_day (user_id, branch_id, work_date) y las filas con
 * borrado lógico (SoftDeletes) chocarían con un único que no incluya deleted_at.
 */
return new class extends Migration
{
    /** nombre => [tabla, columnas] */
    private const INDEXES = [
        'att_tenant_date_user_idx'     => ['attendances', ['tenant_id', 'work_date', 'user_id']],
        'att_tenant_user_date_idx'     => ['attendances', ['tenant_id', 'user_id', 'work_date']],
        'att_tenant_branch_date_idx'   => ['attendances', ['tenant_id', 'branch_id', 'work_date']],
        'att_tenant_status_date_idx'   => ['attendances', ['tenant_id', 'status', 'work_date']],
        'leave_tenant_status_start_idx' => ['leave_requests', ['tenant_id', 'status', 'start_date']],
        'leave_tenant_user_start_idx'  => ['leave_requests', ['tenant_id', 'user_id', 'start_date']],
        'payslip_tenant_user_period_idx' => ['payslips', ['tenant_id', 'user_id', 'period_year', 'period_month']],
        'audit_tenant_created_idx'     => ['audit_logs', ['tenant_id', 'created_at']],
        'audit_tenant_action_created_idx' => ['audit_logs', ['tenant_id', 'action', 'created_at']],
        'notif_notifiable_read_idx'    => ['notifications', ['notifiable_type', 'notifiable_id', 'read_at']],
        'users_tenant_status_role_idx' => ['users', ['tenant_id', 'status', 'role']],
        'eshift_tenant_user_from_idx'  => ['employee_shifts', ['tenant_id', 'user_id', 'effective_from']],
        'docs_tenant_status_idx'       => ['employee_documents', ['tenant_id', 'status']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                continue;
            }

            if ($this->covered($table, $columns, $name)) {
                continue;
            }

            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }

    /** ¿Algún índice existente empieza por exactamente estas columnas (o se llama igual)? */
    private function covered(string $table, array $columns, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strcasecmp($index['name'], $name) === 0) {
                return true;
            }

            $prefix = array_slice(array_map('strtolower', $index['columns']), 0, count($columns));
            if ($prefix === array_map('strtolower', $columns)) {
                return true;
            }
        }

        return false;
    }
};
