<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\SpreadsheetRows;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Services\EmployeeProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importar colaboradores desde la planilla en Excel (sisgere FrmIplanilla). Primero se revisa:
 * nuevos (DNI que no está), ya registrados y los que están en el sistema pero no en el archivo.
 * Al confirmar solo se dan de alta los nuevos (sisgere tampoco daba de baja: solo los mostraba).
 * Todo o nada: con una fila mala no se crea ninguno. El archivo se vuelve a leer al confirmar.
 */
class EmployeeImportController extends Controller
{
    /** Encabezado => [campo, palabras clave]; "Colaborador" sirve si no vienen apellidos y nombres separados */
    private const COLUMNS = [
        'DNI'               => ['dni', ['dni', 'documento']],
        'Apellidos'         => ['last_name', ['apellido']],
        'Nombres'           => ['first_name', ['nombre']],
        'Colaborador'       => ['full_name', ['colaborador', 'trabajador', 'personal']],
        'Cargo'             => ['position', ['cargo', 'puesto']],
        'Correo'            => ['email', ['correo', 'email']],
        'Teléfono'          => ['phone', ['telefono', 'celular']],
        'Dirección'         => ['address', ['direccion', 'domicilio']],
        'Sueldo'            => ['base_salary', ['sueldo', 'remuneracion', 'basico']],
        'Banco'             => ['bank_name', ['banco']],
        'Tipo de cuenta'    => ['bank_account_type', ['tipo']],
        'N.º de cuenta'     => ['bank_account_number', ['cuenta']],
        // sisgere guardaba la ciudad de cada colaborador; aquí lo ubica en la sede de esa ciudad
        'Ciudad'            => ['city', ['ciudad', 'sede']],
    ];

    public function preview(Request $request): JsonResponse
    {
        [$rows, $tenant] = $this->read($request);
        $dnis = array_column($rows, 'dni');
        $existing = User::where('tenant_id', $tenant->id)->whereIn('document_number', $dnis)->pluck('document_number')->all();

        return response()->json([
            'new'      => array_values(array_filter($rows, fn ($r) => ! in_array($r['dni'], $existing, true))),
            'existing' => count($existing),
            // En el sistema y no en el archivo: solo para revisar (no se dan de baja aquí)
            'missing'  => User::where('tenant_id', $tenant->id)->where('role', 'employee')->where('status', 'active')
                ->whereNotIn('document_number', $dnis)->orderBy('last_name')->limit(200)->get(['first_name', 'last_name', 'document_number'])
                ->map(fn ($u) => ['name' => $u->full_name, 'dni' => $u->document_number]),
        ]);
    }

    public function store(Request $request, EmployeeProvisioner $provisioner): JsonResponse
    {
        [$rows, $tenant] = $this->read($request);
        $data = $request->validate(['branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenant->id)]],
            ['branch_id.required' => 'Elige la sede de los nuevos colaboradores.']);
        $existing = User::where('tenant_id', $tenant->id)->whereIn('document_number', array_column($rows, 'dni'))->pluck('document_number')->all();
        $new = array_filter($rows, fn ($r) => ! in_array($r['dni'], $existing, true));

        // Correo único en todo el sistema (como el alta manual)
        $emails = array_filter(array_column($new, 'email'));
        if ($taken = User::whereIn('email', $emails)->pluck('email')->all()) {
            throw ValidationException::withMessages(['file' => 'Estos correos ya los usa otra persona: ' . implode(', ', array_slice($taken, 0, 5)) . '.']);
        }

        // Ciudad de la fila => sede de la empresa con esa ciudad (o ese nombre); si no hay, la sede elegida
        $branchByCity = [];
        foreach (Branch::where('tenant_id', $tenant->id)->where('is_active', true)->get(['id', 'name', 'city']) as $b) {
            foreach (array_filter([$b->city, $b->name]) as $key) {
                $branchByCity[Str::lower(Str::ascii(trim($key)))] ??= $b->id;
            }
        }

        $created = DB::transaction(fn () => collect($new)->map(function ($row) use ($tenant, $provisioner, $data, $branchByCity) {
            $branchId = $branchByCity[Str::lower(Str::ascii((string) $row['city']))] ?? (int) $data['branch_id'];
            [$user] = $provisioner->create($tenant, [
                'first_name' => $row['first_name'], 'last_name' => $row['last_name'], 'email' => $row['email'], 'role' => 'employee',
                'document_type' => 'dni', 'document_number' => $row['dni'], 'phone' => $row['phone'], 'position' => $row['position'],
                'base_salary' => $row['base_salary'],
            ], [$branchId], $branchId);
            // Cuenta del legajo: queda pendiente de que RRHH la verifique (no se paga a una cuenta sin verificar)
            if ($row['address'] || $row['bank_account_number']) {
                EmployeeProfile::updateOrCreate(['user_id' => $user->id], ['tenant_id' => $tenant->id, 'address' => $row['address'],
                    'bank_name' => $row['bank_name'], 'bank_account_type' => $row['bank_account_type'], 'bank_account_number' => $row['bank_account_number'],
                    'payment_info_status' => $row['bank_account_number'] ? 'pending' : null]);
            }

            return $user->full_name;
        }));
        app(\App\Modules\Core\Services\AuditLogger::class)->log('employee.imported', null, ['created' => $created->count(), 'skipped_existing' => count($existing)]);

        return response()->json(['created' => $created->count(), 'existing' => count($existing),
            'message' => "{$created->count()} colaborador(es) nuevo(s) registrado(s)" . ($existing ? '; ' . count($existing) . ' ya estaban registrados.' : '.')]);
    }

    public function template(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'emp') . '.xlsx';
        SpreadsheetRows::template($path, 'Colaboradores', array_diff_key(self::COLUMNS, ['Colaborador' => 1]),
            ['45871236', 'Ruiz Pérez', 'Ana', 'Conductora', 'ana.ruiz@correo.com', '987654321', 'Av. Larco 123, Trujillo', 1800, 'BCP', 'Ahorros', '191-12345678-0-12', 'Trujillo']);

        return response()->download($path, 'plantilla-colaboradores.xlsx')->deleteFileAfterSend();
    }

    /** @return array{0: list<array<string, mixed>>, 1: Tenant} */
    private function read(Request $request): array
    {
        $tenant = Tenant::find(current_tenant_id_or_null());
        abort_unless($tenant, 422, 'Elige una empresa.');
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']],
            ['file.required' => 'Elige el archivo de la planilla.', 'file.mimes' => 'El archivo debe ser Excel (.xlsx, .xls) o CSV.']);

        $rows = [];
        $errors = [];
        $seen = [];
        foreach (SpreadsheetRows::read($request->file('file')->getRealPath(), self::COLUMNS) as $line => $r) {
            $dni = preg_replace('/\D/', '', (string) ($r['dni'] ?? ''));
            $dni = strlen($dni) === 7 ? "0{$dni}" : $dni; // Excel quita el cero inicial del DNI
            [$last, $first] = $this->names($r);
            $email = mb_strtolower(trim((string) ($r['email'] ?? '')));
            $salary = $r['base_salary'] ?? null;
            $error = match (true) {
                strlen($dni) !== 8                                   => 'el DNI debe tener 8 dígitos',
                isset($seen[$dni])                                   => "el DNI {$dni} se repite (fila {$seen[$dni]})",
                $first === '' || $last === ''                        => 'faltan apellidos o nombres',
                $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'el correo no es válido',
                $salary !== null && $salary !== '' && (! is_numeric($salary) || $salary < 0) => 'el sueldo no es un número válido',
                default                                              => null,
            };
            $seen[$dni] ??= $line;
            if ($error) {
                $errors[] = "Fila {$line}: {$error}.";
                if (count($errors) >= 15) {
                    break;
                }
                continue;
            }
            $clean = fn ($k, $max) => ($v = mb_substr(trim((string) ($r[$k] ?? '')), 0, $max)) === '' ? null : $v;
            $rows[] = ['dni' => $dni, 'first_name' => $first, 'last_name' => $last, 'email' => $email ?: null, 'phone' => $clean('phone', 30),
                'position' => $clean('position', 100), 'address' => $clean('address', 250), 'base_salary' => $salary === null || $salary === '' ? null : round((float) $salary, 2),
                'bank_name' => $clean('bank_name', 100), 'bank_account_type' => $this->accountType($r['bank_account_type'] ?? null), 'bank_account_number' => $clean('bank_account_number', 40), 'city' => $clean('city', 100)];
        }
        if ($errors || ! $rows) {
            throw ValidationException::withMessages(['file' => $errors ? implode(' ', $errors) : 'El archivo no tiene colaboradores.']);
        }

        return [$rows, $tenant];
    }

    /** "Ahorros", "CTE", "cuenta sueldo"… al tipo del legajo (savings, checking, payroll); lo desconocido queda vacío */
    private function accountType(mixed $value): ?string
    {
        $v = Str::lower(Str::ascii(trim((string) $value)));

        return match (true) {
            $v === ''                                        => null,
            str_contains($v, 'sueldo') || str_contains($v, 'haber') => 'payroll',
            str_contains($v, 'ahorro') || $v === 'aho'      => 'savings',
            str_contains($v, 'corriente') || str_starts_with($v, 'cte') => 'checking',
            default                                          => null,
        };
    }

    /** Apellidos y nombres; con una sola columna se asume el orden peruano: dos apellidos y luego nombres */
    private function names(array $r): array
    {
        $title = fn ($s) => Str::of(trim((string) $s))->squish()->lower()->title()->toString();
        if (trim((string) ($r['last_name'] ?? '')) !== '' || trim((string) ($r['first_name'] ?? '')) !== '') {
            return [$title($r['last_name'] ?? ''), $title($r['first_name'] ?? '')];
        }
        $parts = preg_split('/\s+/', trim((string) ($r['full_name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);

        return count($parts) < 3 ? [$title($parts[0] ?? ''), $title($parts[1] ?? '')]
            : [$title(implode(' ', array_slice($parts, 0, 2))), $title(implode(' ', array_slice($parts, 2)))];
    }
}
