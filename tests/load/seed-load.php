<?php

/**
 * Carga de volumen para k6 sobre una COPIA de la base (nunca la real):
 *   DB_DATABASE=/ruta/copia-load.sqlite php tests/load/seed-load.php empresa-demo /ruta/salida.json
 *   DB_CONNECTION=mysql DB_DATABASE=tunesa_load php tests/load/seed-load.php empresa-demo /ruta/salida.json
 *
 * Crea en la empresa: 200 órdenes con 12 cuotas (2,400), 1,500 pagos, 600 costos
 * fijos, 1,000 gastos de caja chica, 20 usuarios de Contabilidad y 40
 * colaboradores con token para marcar. Escribe las credenciales en el JSON.
 */

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$slug, $out] = [$argv[1] ?? 'empresa-demo', $argv[2] ?? 'load-users.json'];
$connection = config('database.default');
if (! str_contains((string) config("database.connections.{$connection}.database"), 'load')) {
    exit("Solo sobre una base de prueba: DB_DATABASE debe contener «load» (archivo *load*.sqlite o base tunesa_load)\n");
}

$tenant = Tenant::where('slug', $slug)->firstOrFail();
$t = $tenant->id;
$branch = Branch::withoutGlobalScopes()->where('tenant_id', $t)->firstOrFail();
$now = now();
$month = Carbon::now('America/Lima')->startOfMonth();

DB::transaction(function () use ($t, $now, $month) {
    $supplier = DB::table('suppliers')->insertGetId(['tenant_id' => $t, 'name' => 'Proveedor carga', 'ruc' => '20999000111', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $classes = ['REPUESTOS', 'SERVICIO DE MANTENIMIENTO', 'SUMINISTROS', 'MERCADERÍAS'];
    $base = (int) DB::table('requisitions')->where('tenant_id', $t)->max('number');
    for ($r = 1; $r <= 200; $r++) {
        $rid = DB::table('requisitions')->insertGetId([
            'tenant_id' => $t, 'series' => 'RQ', 'number' => $base + $r, 'requested_at' => $now, 'detail' => "Carga {$r}", 'stage' => 'ordered',
            'classification' => $classes[$r % 4], 'supplier_id' => $supplier, 'currency' => $r % 10 === 0 ? 'USD' : 'PEN',
            'amount' => 1200, 'installments' => 12, 'service_order_number' => sprintf('OS-L%05d', $r), 'created_at' => $now, 'updated_at' => $now,
        ]);
        for ($i = 1; $i <= 12; $i++) {
            $due = $month->copy()->addMonths($i - 4)->addDays($r % 27)->toDateString();
            $paid = $i <= 6;
            $pid = DB::table('payment_installments')->insertGetId([
                'tenant_id' => $t, 'requisition_id' => $rid, 'installment' => $i, 'due_date' => $due, 'amount' => 100,
                'paid_amount' => $paid ? 100 : 0, 'status' => $paid ? 'paid' : 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($paid && ($r * 12 + $i) % 8 !== 0) {
                DB::table('payment_cancellations')->insert(['tenant_id' => $t, 'payment_installment_id' => $pid, 'paid_at' => $due, 'amount' => 100, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
    for ($f = 1; $f <= 600; $f++) {
        $date = $month->copy()->addMonths(($f % 9) - 3)->addDays($f % 25);
        DB::table('fixed_costs')->insert([
            'tenant_id' => $t, 'classification' => ['ARRENDAMIENTO', 'LEASING', 'SEGUROS - SOAT'][$f % 3], 'supplier_name' => 'Costo carga', 'detail' => "Carga {$f}",
            'date' => $date->toDateString(), 'period' => $date->format('Y-m'), 'amount' => 350, 'currency' => 'PEN', 'installment' => 1, 'batch' => 100000 + $f,
            'is_active' => 1, 'paid_at' => $f % 2 ? $date->toDateString() : null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $receipt = (int) DB::table('petty_cash_expenses')->where('tenant_id', $t)->max('receipt_number');
    for ($p = 1; $p <= 1000; $p++) {
        DB::table('petty_cash_expenses')->insert([
            'tenant_id' => $t, 'receipt_number' => $receipt + $p, 'date' => $month->copy()->addMonths(($p % 6) - 3)->addDays($p % 26)->toDateString(),
            'description' => "Gasto carga {$p}", 'amount' => 12.5, 'cash_box' => 'CAJA EFECTIVO', 'document_type' => 'BOLETA', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
});

$password = bin2hex(random_bytes(6));
$hash = Hash::make($password);
$accountants = [];
for ($i = 1; $i <= 20; $i++) {
    $email = "carga.contabilidad{$i}@empresa-demo.load";
    User::withoutGlobalScopes()->updateOrCreate(['email' => $email], [
        'tenant_id' => $t, 'first_name' => 'Carga', 'last_name' => "Contabilidad {$i}", 'role' => 'accountant', 'status' => 'active',
        'password' => $hash, 'must_change_password' => false, 'document_number' => sprintf('70%06d', $i),
    ]);
    $accountants[] = $email;
}

$employees = [];
for ($i = 1; $i <= 40; $i++) {
    $u = User::withoutGlobalScopes()->updateOrCreate(['email' => "carga.colaborador{$i}@empresa-demo.load"], [
        'tenant_id' => $t, 'first_name' => 'Carga', 'last_name' => "Colaborador {$i}", 'role' => 'employee', 'status' => 'active',
        'password' => $hash, 'must_change_password' => false, 'document_number' => sprintf('71%06d', $i),
    ]);
    $u->branches()->syncWithoutDetaching([$branch->id => ['is_primary' => true, 'assigned_from' => '2020-01-01']]);
    $employees[] = ['id' => $u->id, 'token' => $u->createToken('k6')->plainTextToken];
}

file_put_contents($out, json_encode([
    'tenant' => $slug, 'password' => $password, 'accountants' => $accountants, 'employees' => $employees,
    'branch' => ['lat' => (float) $branch->latitude, 'lng' => (float) $branch->longitude],
], JSON_PRETTY_PRINT));
echo "Listo: 2,400 cuotas, 600 costos fijos, 1,000 gastos, 20 contadores y 40 colaboradores → {$out}\n";
