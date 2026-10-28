<?php

namespace App\Modules\HR\Controllers\Api\Employee;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Autoservicio del colaborador desde la app: perfil, configuración de la
 * empresa (qué módulos ve), datos de pago y previsión, y contacto.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Configuración de módulos para el renderizado condicional de la app.
     * GET /api/employee/config
     */
    public function config(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => CompanyConfiguration::current()->toApi()]);
    }

    /**
     * Perfil completo del colaborador (datos de pago enmascarados).
     * GET /api/employee/profile
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => self::payload($request->user())]);
    }

    /**
     * Cuenta bancaria y régimen previsional. Queda "por verificar" hasta que
     * RRHH lo revise. Si la empresa lo bloquea tras verificar, responde 423.
     * PUT /api/employee/payment-info
     */
    public function updatePaymentInfo(Request $request): JsonResponse
    {
        $config = CompanyConfiguration::current();

        if (! $config->allows('mobile_payment_info_edit')) {
            return $this->moduleDisabled('Tu empresa no permite actualizar los datos de pago desde la app.');
        }

        $user    = $request->user();
        $profile = $user->profile ?? new EmployeeProfile(['user_id' => $user->id]);

        if ($config->lock_verified_payment_info && $profile->payment_info_status === 'verified') {
            return response()->json([
                'success' => false,
                'message' => 'RRHH ya verificó tus datos de pago. Para cambiarlos, comunícate con RRHH.',
                'code'    => 'PAYMENT_INFO_LOCKED',
            ], 423);
        }

        $validated = $request->validate([
            'bank_name'             => ['required', 'string', 'max:100'],
            'bank_account_type'     => ['required', Rule::in(array_keys(EmployeeProfile::BANK_ACCOUNT_TYPES))],
            'bank_account_currency' => ['required', 'string', 'size:3', 'alpha'],
            'bank_account_number'   => ['required', 'string', 'regex:/^[0-9\- ]{6,30}$/'],
            'interbank_code'        => ['nullable', 'string', 'regex:/^[0-9\- ]{10,30}$/'],
            'pension_system'        => ['required', Rule::in(array_keys(EmployeeProfile::PENSION_SYSTEMS))],
            'pension_fund'          => ['nullable', 'required_if:pension_system,afp', 'string', 'max:100'],
            'pension_code'          => ['nullable', 'string', 'alpha_num', 'max:20'],
            'health_insurance'      => ['nullable', Rule::in(array_keys(EmployeeProfile::HEALTH_INSURANCES))],
        ], [
            'bank_account_number.regex' => 'El número de cuenta solo admite dígitos (6 a 30).',
            'interbank_code.regex'      => 'El CCI / CLABE / CBU solo admite dígitos (10 a 30).',
            'pension_fund.required_if'  => 'Elige tu AFP.',
        ]);

        foreach (['bank_account_number', 'interbank_code'] as $field) {
            if (! empty($validated[$field])) {
                $validated[$field] = preg_replace('/\D/', '', $validated[$field]);
            }
        }
        $validated['bank_account_currency'] = strtoupper($validated['bank_account_currency']);

        $profile->fill($validated + [
            'tenant_id'                => $user->tenant_id,
            'payment_info_status'      => 'pending',
            'payment_info_updated_at'  => now(),
            'payment_verified_by'      => null,
            'payment_verified_at'      => null,
            'payment_rejection_reason' => null,
        ]);
        $this->audit->saveWithAudit('payment_info.updated_by_employee', $profile);

        return response()->json([
            'success' => true,
            'message' => 'Datos guardados. RRHH los verificará antes del próximo pago.',
            'data'    => self::payload($user->fresh()),
        ]);
    }

    /**
     * Dirección y contacto de emergencia (siempre editable por el colaborador).
     * PUT /api/employee/contact
     */
    public function updateContact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address'                 => ['nullable', 'string', 'max:255'],
            'district'                => ['nullable', 'string', 'max:100'],
            'emergency_contact_name'  => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\- ()]{6,30}$/'],
        ]);

        $user = $request->user();
        EmployeeProfile::updateOrCreate(['user_id' => $user->id], $validated + ['tenant_id' => $user->tenant_id]);

        return response()->json(['success' => true, 'message' => 'Contacto actualizado.', 'data' => self::payload($user->fresh())]);
    }

    /**
     * Respuesta común de perfil (app y vista de RRHH).
     */
    public static function payload(User $user, bool $fullSensitive = false): array
    {
        $config   = CompanyConfiguration::current();
        $profile  = $user->profile ?? new EmployeeProfile(['payment_info_status' => 'incomplete']);
        $profile->setRelation('user', $user);
        $sizes    = $user->uniformSize;
        $docs     = $user->documents()->current()->get();
        $required = $config->requiredDocumentsFor($user); // empresa + su cargo

        $missingDocs = array_values(array_diff($required, $docs->where('status', 'approved')->pluck('type')->all()));

        $steps = [
            'payment_info' => $profile->hasCompletePaymentInfo(),
            'documents'    => $missingDocs === [],
            'uniforms'     => (bool) $sizes?->isComplete(),
            'contact'      => (bool) ($profile->address && $profile->emergency_contact_phone),
        ];

        return [
            'user' => [
                'id'              => $user->id,
                'full_name'       => $user->full_name,
                'first_name'      => $user->first_name,
                'last_name'       => $user->last_name,
                'email'           => $user->email,
                'phone'           => $user->phone,
                'document_type'   => $user->document_type,
                'document_number' => $user->document_number,
                'employee_code'   => $user->employee_code,
                'position'        => $user->position,
                'position_id'     => $user->position_id,
                'department'      => $user->department,
                'hire_date'       => $user->hire_date?->toDateString(),
                'contract_type'   => $user->contract_type,
                'role'            => $user->role,
            ],
            'profile'  => $profile->toApi($fullSensitive),
            'uniforms' => [
                'sizes'      => $sizes?->toApi(),
                'deliveries' => $user->uniformDeliveries()->latest('delivered_at')->limit(20)->get()->map->toApi(),
            ],
            'documents' => [
                'items'    => $docs->map->toApi()->values(),
                'required' => $required,
                'missing'  => $missingDocs,
            ],
            'onboarding' => [
                'steps'    => $steps,
                'progress' => (int) round(100 * count(array_filter($steps)) / count($steps)),
            ],
            'config' => $config->toApi(),
            'catalogs' => [
                'bank_account_types' => EmployeeProfile::BANK_ACCOUNT_TYPES,
                'pension_systems'    => EmployeeProfile::PENSION_SYSTEMS,
                'health_insurances'  => EmployeeProfile::HEALTH_INSURANCES,
                'document_types'     => array_intersect_key(EmployeeDocument::TYPES, array_flip(EmployeeDocument::EMPLOYEE_TYPES)),
                'shirt_sizes'        => \App\Modules\HR\Models\UniformSize::SHIRT_SIZES,
                'pants_sizes'        => \App\Modules\HR\Models\UniformSize::PANTS_SIZES,
                'shoe_sizes'         => \App\Modules\HR\Models\UniformSize::SHOE_SIZES,
                'shoe_types'         => \App\Modules\HR\Models\UniformSize::SHOE_TYPES,
                'banks'              => config('payroll.banks', []),
                'pension_funds'      => config('payroll.pension_funds', []),
            ],
        ];
    }

    private function moduleDisabled(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => 'MODULE_DISABLED'], 403);
    }
}
