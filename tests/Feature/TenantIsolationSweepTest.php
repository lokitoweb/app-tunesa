<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Barrido de aislamiento multiempresa: TODO modelo con BelongsToTenant
 * (descubierto por reflexión en app/Models) debe ocultar las filas de otra
 * empresa en query(), find(), count() y exists() con la empresa A activa.
 * Si alguien agrega un modelo con tenant_id, entra solo en esta prueba.
 */
class TenantIsolationSweepTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * Con tenant_id pero sin TenantScope a propósito: los roles del sistema y la
     * fila global de "Marca y app" tienen tenant_id NULL y se resuelven
     * explícitamente por empresa (ver test_tenant_settings_resolve_per_tenant).
     * WebhookDelivery: filas de plataforma con tenant_id NULL; se consulta con ofTenant() (ver IntegrationDeliveriesTest).
     */
    private const UNSCOPED_BY_DESIGN = ['App\\Models\\Role', 'App\\Models\\Permission', 'App\\Models\\TenantSetting', 'App\\Models\\WebhookDelivery'];

    /** Referencias dentro de la misma empresa que necesita cada fila */
    private array $refs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** @return array<class-string<Model>> */
    private function tenantModels(): array
    {
        $models = [];
        foreach ((new Finder())->files()->in(app_path('Models'))->depth(0)->name('*.php') as $file) {
            $class = 'App\\Models\\' . $file->getBasename('.php');
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if (! $reflection->isAbstract() && $reflection->isSubclassOf(Model::class)
                && in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }
        sort($models);

        return $models;
    }

    public function test_reflection_discovers_every_tenant_model(): void
    {
        $models = $this->tenantModels();

        foreach (['User', 'Branch', 'Shift', 'Attendance', 'LeaveRequest', 'Payslip', 'AuditLog', 'EmployeeDocument', 'CompanyConfiguration'] as $expected) {
            $this->assertContains("App\\Models\\{$expected}", $models);
        }

        // Toda tabla con tenant_id de un modelo debe usar BelongsToTenant
        foreach ((new Finder())->files()->in(app_path('Models'))->depth(0)->name('*.php') as $file) {
            $class = 'App\\Models\\' . $file->getBasename('.php');
            if (! is_subclass_of($class, Model::class) || $class === Tenant::class || in_array($class, self::UNSCOPED_BY_DESIGN, true)) {
                continue;
            }
            $table = (new $class())->getTable();
            if (Schema::hasColumn($table, 'tenant_id')) {
                $this->assertContains($class, $models, "{$class} tiene tenant_id pero no usa BelongsToTenant");
            }
        }
    }

    public function test_tenant_a_context_never_returns_tenant_b_rows(): void
    {
        $a = $this->makeTenant('acme');
        $b = $this->makeTenant('other');
        $this->refs[$a->id] = $this->refsFor($a);
        $this->refs[$b->id] = $this->refsFor($b);

        $checked = [];
        foreach ($this->tenantModels() as $class) {
            /** @var Model $model */
            $model = new $class();
            $table = $model->getTable();

            $mine    = $this->insertRow($table, $a);
            $foreign = $this->insertRow($table, $b);

            $ctx = app(TenantContext::class);
            $ctx->set($a);

            $ids = $class::query()->pluck($model->getKeyName())->map(fn ($id) => (string) $id)->all();
            $this->assertContains((string) $mine, $ids, "{$class}: no ve su propia fila");
            $this->assertNotContains((string) $foreign, $ids, "{$class}::query() devuelve filas de otra empresa");
            $this->assertNull($class::find($foreign), "{$class}::find() devuelve una fila de otra empresa");
            $this->assertNotNull($class::find($mine), "{$class}::find() no encuentra su propia fila");
            $this->assertFalse($class::whereKey($foreign)->exists(), "{$class}::exists() ve otra empresa");
            $this->assertSame(0, $class::query()->where('tenant_id', $b->id)->count(), "{$class}: filtrar por tenant_id ajeno devuelve filas");

            $ctx->clear();
            $checked[] = class_basename($class);
        }

        $this->assertGreaterThanOrEqual(14, count($checked), implode(', ', $checked));
    }

    public function test_tenant_settings_resolve_per_tenant(): void
    {
        $a = $this->makeTenant('acme');
        $b = $this->makeTenant('other');
        TenantSetting::updateOrCreate(['tenant_id' => $b->id], ['trade_name' => 'Marca de Other', 'webhook_url' => 'https://other.test/hook']);

        // Acme solo tiene su secreto de webhook (generado al crearse), nada de Other
        $this->assertNull(TenantSetting::rowFor($a)->trade_name);
        $this->assertNull(TenantSetting::rowFor($a)->webhook_url);
        $this->assertNotSame(TenantSetting::rowFor($a)->webhook_secret, TenantSetting::rowFor($b)->webhook_secret);
        $this->assertNotSame('Marca de Other', TenantSetting::forTenant($a)->trade_name);
        $this->assertNotSame('https://other.test/hook', TenantSetting::forTenant($a)->webhook_url);

        $admin = $this->makeUser($a, 'company_admin');
        $this->actingIn($admin, $a)->getJson('/api/admin/app-config')->assertOk()->assertDontSee('Marca de Other')->assertDontSee('other.test');
    }

    public function test_audit_log_web_and_notifications_api_stay_within_the_tenant(): void
    {
        $a      = $this->makeTenant('acme');
        $b      = $this->makeTenant('other');
        $adminA = $this->makeUser($a, 'company_admin', ['first_name' => 'Ana']);
        $adminB = $this->makeUser($b, 'company_admin', ['first_name' => 'Bruno']);

        AuditLog::create(['tenant_id' => $b->id, 'user_id' => $adminB->id, 'action' => 'payroll.exported', 'meta' => ['period' => '2031-01'], 'ip' => '203.0.113.9']);

        // Ni filtrando por la acción o por el usuario de la otra empresa
        $this->actingAs($adminA)->get('/audit')->assertOk()->assertDontSee('2031-01')->assertDontSee('Bruno');
        $this->get('/audit?action=payroll.exported')->assertOk()->assertDontSee('2031-01');
        $this->get("/audit?user_id={$adminB->id}")->assertOk()->assertDontSee('203.0.113.9')->assertDontSee('Bruno');

        // Notificaciones: cada quien solo las suyas
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\PayslipPublished', 'notifiable_type' => User::class,
            'notifiable_id' => $adminB->id, 'data' => json_encode(['type' => 'payslip.published', 'title' => 'Boleta de Bruno', 'body' => 'x']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingIn($adminA, $a)->getJson('/api/notifications')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingIn($adminA, $a)->postJson("/api/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($adminA)->get('/notifications')->assertOk()->assertDontSee('Boleta de Bruno');
        $this->actingAs($adminA)->post("/notifications/{$id}/read")->assertNotFound();
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    // =========================================================================

    /** Usuario, sucursal y turno de la empresa para llenar llaves foráneas */
    private function refsFor(Tenant $tenant): array
    {
        $user   = $this->makeUser($tenant, 'employee');
        $branch = $this->makeBranch($tenant);
        $shift  = DB::table('shifts')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Turno ' . $tenant->slug, 'start_time' => '08:00:00', 'end_time' => '17:00:00',
            'working_days' => '[1,2,3,4,5]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['user' => $user->id, 'branch' => $branch->id, 'shift' => $shift];
    }

    /**
     * Inserta una fila mínima válida (sin eventos de modelo) llenando las
     * columnas obligatorias según el esquema y los CHECK de los enums.
     */
    private function insertRow(string $table, Tenant $tenant): int|string
    {
        if ($table === 'users') {
            return $this->makeUser($tenant)->id;
        }

        $refs   = $this->refs[$tenant->id];
        $checks = $this->enumChecks($table);
        $row    = ['tenant_id' => $tenant->id];

        foreach (Schema::getColumns($table) as $col) {
            $name = $col['name'];
            if ($name === 'id' || array_key_exists($name, $row) || $col['auto_increment']) {
                continue;
            }
            $required = ! $col['nullable'] && $col['default'] === null;

            $row[$name] = match (true) {
                $name === 'user_id'                                => $refs['user'],
                $name === 'branch_id'                              => $refs['branch'],
                $name === 'shift_id'                               => $refs['shift'],
                str_ends_with($name, '_by') || $name === 'supervisor_id' => $col['nullable'] ? null : $refs['user'],
                ! $required                                        => null,
                isset($checks[$name])                              => $checks[$name][0],
                in_array($name, ['created_at', 'updated_at'], true) => now(),
                str_contains($col['type'], 'int') || str_contains($col['type'], 'numeric') || str_contains($col['type'], 'decimal') || str_contains($col['type'], 'float') => 1,
                str_contains($col['type'], 'date') || str_contains($col['type'], 'time') => str_ends_with($name, '_time') ? '08:00:00' : now()->toDateString(),
                str_contains($col['type'], 'json') || in_array($name, ['working_days', 'required_documents', 'meta'], true) => '[]',
                default                                            => $name . '-' . $tenant->slug . '-' . Str::random(4),
            };

            if ($row[$name] === null) {
                unset($row[$name]);
            }
        }

        // Filas únicas por (tenant, fecha) o (tenant, usuario): distintas por empresa
        if ($table === 'holidays') {
            $row['date'] = $tenant->slug === 'acme' ? '2030-01-01' : '2030-01-02';
        }

        return DB::table($table)->insertGetId($row);
    }

    /** Valores permitidos de los enums de SQLite: "col" varchar check ("col" in ('a', 'b')) */
    private function enumChecks(string $table): array
    {
        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
        preg_match_all('/"(\w+)" in \(([^)]*)\)/', $sql, $m, PREG_SET_ORDER);

        $checks = [];
        foreach ($m as [, $column, $list]) {
            preg_match_all("/'([^']*)'/", $list, $values);
            $checks[$column] = $values[1];
        }

        return $checks;
    }
}
