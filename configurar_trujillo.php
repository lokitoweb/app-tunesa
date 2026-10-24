<?php

/**
 * Configuración puntual para Turismo Negreiros (se ejecuta una sola vez y se puede repetir sin duplicar):
 *
 *  1. Crea (o actualiza) el colaborador 40767470@turismonegreiros.com, cargo "Atención al cliente",
 *     con la contraseña 40767470 (la app le pedirá cambiarla en su primer ingreso).
 *  2. Pone la sede de marcación en "Av. César Vallejo N° 1388, Trujillo 13006" con un radio estrecho.
 *  3. Endurece la geocerca de la empresa: fuera del radio se RECHAZA, GPS falso se RECHAZA
 *     y no se acepta una lectura de GPS con precisión peor que MAX_ACCURACY.
 *
 * Uso (desde la carpeta del proyecto Laravel, la misma donde está "artisan"):
 *     php configurar_trujillo.php                       -> usa la única sede activa
 *     php configurar_trujillo.php 3                     -> sede con id 3
 *
 * Las coordenadas de la sede ya vienen fijadas abajo (LATITUDE / LONGITUDE).
 */

const DOCUMENT     = '40767470';
const PASSWORD     = '40767470';
const POSITION     = 'Atención al cliente';
const ADDRESS      = 'Av. César Vallejo N° 1388, Trujillo 13006';
const CITY         = 'Trujillo';
const RADIUS       = 100;  // metros alrededor del pin en los que se acepta la marcación
const LATITUDE     = -8.11823;   // puerta del local en Av. César Vallejo N° 1388
const LONGITUDE    = -79.02052;
const MAX_ACCURACY = 30;  // metros: lecturas de GPS menos precisas se rechazan

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\Position;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Onboarding\Services\OnboardingService;
use Illuminate\Support\Facades\DB;

[$branchId, $lat, $lng] = [$argv[1] ?? null, $argv[2] ?? LATITUDE, $argv[3] ?? LONGITUDE];

$domain = TenantSetting::forTenant(null)->login_email_domain ?: 'turismonegreiros.com';
$email  = DOCUMENT . '@' . $domain;

$tenants = Tenant::withoutGlobalScopes()->get();
$tenant  = $tenants->count() === 1
    ? $tenants->first()
    : $tenants->first(fn ($t) => str_contains((string) $t->custom_domain . ' ' . $t->email, 'turismonegreiros'));
if (! $tenant) {
    exit("No encontré la empresa de Turismo Negreiros. Empresas: " . $tenants->pluck('company_name', 'id')->toJson() . "\n");
}

$branches = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true)->get();
$branch   = $branchId 
    ? $branches->firstWhere('id', (int) $branchId) 
    : ($branches->first(fn ($b) => str_contains(strtolower($b->name . ' ' . $b->address), 'vallejo') || str_contains(strtolower($b->name), 'trujillo')) ?: ($branches->count() === 1 ? $branches->first() : null));
if (! $branch) {
    echo "Indica el id de la sede de Trujillo como primer argumento. Sedes activas:\n";
    foreach ($branches as $b) {
        echo "  {$b->id}  {$b->name}  ({$b->address})\n";
    }
    exit(1);
}

if (($lat === null) !== ($lng === null) || ($lat !== null && (! is_numeric($lat) || ! is_numeric($lng)))) {
    exit("Las coordenadas van juntas y en número, p. ej.: php configurar_trujillo.php {$branch->id} -8.0960 -79.0335\n");
}

DB::transaction(function () use ($tenant, $branch, $email, $lat, $lng) {
    // --- 1. Sede y geocerca ---
    $branch->fill(['address' => ADDRESS, 'city' => CITY, 'allowed_radius_meters' => RADIUS]);
    if ($lat !== null) {
        $branch->fill(['latitude' => (float) $lat, 'longitude' => (float) $lng]);
    }
    $branch->save();

    $config = CompanyConfiguration::withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenant->id]);
    $config->geofence_policy = 'reject';
    $config->save();

    $settings = TenantSetting::editableRowFor($tenant);
    $settings->mock_location_policy    = 'reject';
    $settings->max_gps_accuracy_meters = MAX_ACCURACY;
    $settings->save();

    // --- 2. Colaborador de atención al cliente ---
    $position = Position::withoutGlobalScopes()->firstOrCreate(
        ['tenant_id' => $tenant->id, 'name' => POSITION],
        ['is_active' => true],
    );

    $user = User::withoutGlobalScopes()->where('email', $email)->first();
    $isNew = ! $user;
    $user ??= new User(['tenant_id' => $tenant->id, 'first_name' => 'Atención', 'last_name' => 'al Cliente']);
    $user->fill([
        'email'           => $email,
        'document_type'   => 'dni',
        'document_number' => DOCUMENT,
        'role'            => 'employee',
        'status'          => 'active',
        'position_id'     => $position->id,
        'position'        => POSITION,
    ]);
    // Clave fija pedida por la empresa; se marca como temporal para que la cambie al entrar
    $user->forceFill([
        'tenant_id'                => $tenant->id,
        'password'                 => PASSWORD,
        'must_change_password'     => true,
        'temp_password_expires_at' => now()->addHours(72),
    ])->save();

    $user->branches()->syncWithoutDetaching([$branch->id => ['is_primary' => true]]);

    if ($isNew) {
        app(OnboardingService::class)->startFor($user);
    }

    echo ($isNew ? 'Creado' : 'Actualizado') . " el usuario {$email} (id {$user->id}), cargo " . POSITION . ", sede {$branch->name}.\n";
});

$branch->refresh();
echo "Sede {$branch->name}: " . ADDRESS . " | pin {$branch->latitude}, {$branch->longitude} | radio " . RADIUS . " m\n";
echo "Geocerca: fuera del radio se rechaza; GPS falso se rechaza; precisión máxima " . MAX_ACCURACY . " m.\n";
if ($lat === null) {
    echo "IMPORTANTE: el pin no se movió. Ajústalo en el panel (Sucursales > Editar) sobre la puerta del local.\n";
}
