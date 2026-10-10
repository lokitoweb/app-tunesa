<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Índices compuestos para reportes: se crean solo si no existe uno equivalente */
class CompositeIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function indexNames(string $table): array
    {
        return array_column(Schema::getIndexes($table), 'name');
    }

    public function test_creates_missing_indexes_without_duplicating_existing_ones(): void
    {
        $this->assertContains('att_tenant_date_user_idx', $this->indexNames('attendances'));
        $this->assertContains('leave_tenant_status_start_idx', $this->indexNames('leave_requests'));
        $this->assertContains('audit_tenant_created_idx', $this->indexNames('audit_logs'));
        $this->assertContains('users_tenant_status_role_idx', $this->indexNames('users'));
        $this->assertContains('eshift_tenant_user_from_idx', $this->indexNames('employee_shifts'));

        // Ya cubiertos por los índices originales: no se duplican
        $this->assertNotContains('att_tenant_user_date_idx', $this->indexNames('attendances'));
        $this->assertNotContains('att_tenant_branch_date_idx', $this->indexNames('attendances'));
        $this->assertNotContains('payslip_tenant_user_period_idx', $this->indexNames('payslips'));
        $this->assertNotContains('notif_notifiable_read_idx', $this->indexNames('notifications'));
        $this->assertNotContains('docs_tenant_status_idx', $this->indexNames('employee_documents'));
    }

    public function test_down_and_up_are_idempotent(): void
    {
        $migration = require app_path('Modules/Core/Database/Migrations/2026_10_14_000001_add_composite_indexes_for_reports.php');

        $migration->down();
        $this->assertNotContains('users_tenant_status_role_idx', $this->indexNames('users'));
        $this->assertContains('users_tenant_id_status_index', $this->indexNames('users'), 'No toca los índices originales');

        $migration->up();
        $migration->up();
        $this->assertSame(1, count(array_keys($this->indexNames('users'), 'users_tenant_status_role_idx')));
    }
}
