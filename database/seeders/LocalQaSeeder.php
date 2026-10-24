<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\Signatory;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios de QA por rol para recorrer la traza de Logística y Tesorería en local
 * (php artisan db:seed --class=LocalQaSeeder). Nunca corre fuera de local y no toca
 * al Super Admin ni a usuarios existentes que no sean de QA.
 *
 * Contraseña de todos: QaLocal2026!
 */
class LocalQaSeeder extends Seeder
{
    public const PASSWORD = 'QaLocal2026!';

    public const USERS = [
        'qa.solicitante@tunesa.test' => ['Sofía', 'Solicitante', 'supervisor', '70000001'],
        'qa.gerente@tunesa.test'     => ['Gonzalo', 'Gerente', 'manager', '70000002'],
        'qa.logistica@tunesa.test'   => ['Lucía', 'Logística', 'logistics', '70000003'],
        'qa.tesoreria@tunesa.test'   => ['Tomás', 'Tesorería', 'accountant', '70000004'],
    ];

    public function run(): void
    {
        abort_unless(app()->environment('local'), 403, 'LocalQaSeeder solo corre en local.');
        $tenant = Tenant::orderBy('id')->firstOrFail();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->firstOrFail();
        $branch->update(['document_series' => $branch->document_series ?: '001']);

        foreach (self::USERS as $email => [$first, $last, $role, $dni]) {
            $user = User::withoutGlobalScopes()->updateOrCreate(['email' => $email], [
                'tenant_id' => $tenant->id, 'first_name' => $first, 'last_name' => $last, 'role' => $role, 'document_number' => $dni,
                'password' => Hash::make(self::PASSWORD), 'status' => 'active', 'must_change_password' => false,
            ]);
            $user->branches()->syncWithoutDetaching([$branch->id => ['is_primary' => true]]);
        }

        $manager = User::withoutGlobalScopes()->where('email', 'qa.gerente@tunesa.test')->first();
        Signatory::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id, 'user_id' => $manager->id], ['position' => 'Gerente general', 'can_authorize' => true, 'is_active' => true]);
        Vehicle::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id, 'plate' => 'QA-001'], ['type' => 'BUS', 'brand' => 'Volvo', 'model' => 'B450R', 'branch_id' => $branch->id]);
        Supplier::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id, 'ruc' => '20999999991'], ['name' => 'PROVEEDOR QA SAC', 'email' => 'proveedor.qa@tunesa.test', 'is_active' => true]);
        CompanyBankAccount::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id, 'bank' => 'BCP', 'account_number' => '191-QA-0001'], ['currency' => 'PEN', 'alias' => 'Cuenta QA', 'is_active' => true]);
    }
}
