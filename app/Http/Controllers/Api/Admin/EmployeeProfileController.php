<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Employee\ProfileController;
use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ficha laboral de un colaborador vista por RRHH: régimen laboral, CTS,
 * verificación de la cuenta de abono. Los números completos solo se
 * devuelven con el permiso profiles.view_sensitive y quedan auditados.
 */
class EmployeeProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/admin/employees/{id}/profile?reveal=1 */
    public function show(Request $request, int $id): JsonResponse
    {
        $employee = User::findOrFail($id);
        abort_unless($request->user()->canManage($employee), 403);

        $reveal = $request->boolean('reveal') && $request->user()->hasPermission('profiles.view_sensitive');

        if ($reveal) {
            $this->audit->log('payment_info.revealed', $employee);
        }

        return response()->json(['success' => true, 'data' => ProfileController::payload($employee, $reveal)]);
    }

    /** PUT /api/admin/employees/{id}/profile */
    public function update(Request $request, int $id): JsonResponse
    {
        $employee = User::findOrFail($id);
        abort_unless($request->user()->canManage($employee), 403);

        $validated = $request->validate([
            'address'                 => ['sometimes', 'nullable', 'string', 'max:255'],
            'district'                => ['sometimes', 'nullable', 'string', 'max:100'],
            'emergency_contact_name'  => ['sometimes', 'nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'bank_name'               => ['sometimes', 'nullable', 'string', 'max:100'],
            'bank_account_type'       => ['sometimes', 'nullable', Rule::in(array_keys(EmployeeProfile::BANK_ACCOUNT_TYPES))],
            'bank_account_currency'   => ['sometimes', 'nullable', 'string', 'size:3'],
            'bank_account_number'     => ['sometimes', 'nullable', 'string', 'regex:/^[0-9\- ]{6,30}$/'],
            'interbank_code'          => ['sometimes', 'nullable', 'string', 'regex:/^[0-9\- ]{10,30}$/'],
            'pension_system'          => ['sometimes', 'nullable', Rule::in(array_keys(EmployeeProfile::PENSION_SYSTEMS))],
            'pension_fund'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'pension_code'            => ['sometimes', 'nullable', 'string', 'alpha_num', 'max:20'],
            'health_insurance'        => ['sometimes', 'nullable', Rule::in(array_keys(EmployeeProfile::HEALTH_INSURANCES))],
            'labor_regime'            => ['sometimes', 'nullable', Rule::in(array_keys(EmployeeProfile::LABOR_REGIMES))],
            'cts_bank_name'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'cts_account_number'      => ['sometimes', 'nullable', 'string', 'regex:/^[0-9\- ]{6,30}$/'],
            'cts_currency'            => ['sometimes', 'nullable', 'string', 'size:3'],
        ]);

        foreach (['bank_account_number', 'interbank_code', 'cts_account_number'] as $field) {
            if (! empty($validated[$field])) {
                $validated[$field] = preg_replace('/\D/', '', $validated[$field]);
            }
        }

        $profile = EmployeeProfile::firstOrNew(['user_id' => $employee->id]);
        $profile->fill($validated + ['tenant_id' => $employee->tenant_id]);

        // Lo que registra RRHH queda verificado por quien lo registró
        if (array_intersect(array_keys($validated), EmployeeProfile::EMPLOYEE_PAYMENT_FIELDS)) {
            $profile->fill([
                'payment_info_status'     => $profile->hasCompletePaymentInfo() ? 'verified' : 'incomplete',
                'payment_info_updated_at' => now(),
                'payment_verified_by'     => $request->user()->id,
                'payment_verified_at'     => now(),
            ]);
        }

        $profile->save();
        $this->audit->log('profile.updated_by_hr', $profile, ['fields' => array_keys($validated)]);

        return response()->json(['success' => true, 'message' => 'Ficha actualizada.', 'data' => ProfileController::payload($employee->fresh())]);
    }

    /**
     * Verificar u observar los datos de pago enviados desde la app.
     * POST /api/admin/employees/{id}/payment-info/review  {decision: verified|rejected, reason?}
     */
    public function reviewPayment(Request $request, int $id): JsonResponse
    {
        $employee = User::findOrFail($id);
        abort_unless($request->user()->canManage($employee), 403);

        $validated = $request->validate([
            'decision' => ['required', 'in:verified,rejected'],
            'reason'   => ['required_if:decision,rejected', 'nullable', 'string', 'max:255'],
        ]);

        $profile = EmployeeProfile::where('user_id', $employee->id)->firstOrFail();

        $profile->update([
            'payment_info_status'      => $validated['decision'],
            'payment_rejection_reason' => $validated['decision'] === 'rejected' ? $validated['reason'] : null,
            'payment_verified_by'      => $request->user()->id,
            'payment_verified_at'      => now(),
        ]);

        $this->audit->log("payment_info.{$validated['decision']}", $profile);

        return response()->json(['success' => true, 'data' => $profile->fresh()->toApi()]);
    }
}
