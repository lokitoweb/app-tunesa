<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\OrgScope;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\EmployeeTransfer;
use App\Modules\Core\Models\Position;
use App\Modules\Onboarding\Models\OnboardingProcess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Traslado de un colaborador a otra empresa del grupo (como lo hacen los grupos empresariales: son
 * empleadores distintos, cada uno con su RUC):
 *
 *  Origen  → cese el día anterior al ingreso en destino, inactivo, sin sesiones ni teléfono; toda su
 *            historia (asistencia, boletas, CTS, liquidación, legajo) se queda en su empresa.
 *  Destino → registro nuevo con sus datos personales (ficha, cuenta, pensiones, documentos personales
 *            si se eligen) y los de la nueva empresa (sede, cargo, rol, sueldo, fecha de ingreso),
 *            mismo correo y contraseña: en la app y la web entra directo a la empresa nueva.
 *  Ambos quedan enlazados (employee_transfers) y en la bitácora de cada empresa.
 *
 * Cada escritura se hace dentro de su empresa (TenantContext), sin saltarse el aislamiento.
 */
final class EmployeeTransferService
{
    /** Documentos personales (van con la persona); contratos y exámenes ocupacionales son del empleador */
    public const PERSONAL_DOCUMENTS = ['dni', 'cv', 'proof_of_address', 'certificate', 'driver_license', 'pension_exemption', 'other'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{tenant_id: int, effective_date: string, branch_id: int, role: string, position_id?: ?int,
     *               base_salary?: ?float, copy_documents?: bool, onboarding_required?: bool, notes?: ?string}  $data
     */
    public function transfer(User $employee, array $data, User $actor): EmployeeTransfer
    {
        abort_if(app(OrgScope::class)->restricted(), 403, 'Para trasladar entre empresas necesitas alcance de toda la empresa.');
        abort_unless($employee->status === 'active' && $employee->tenant_id, 422, 'Solo se traslada a un colaborador activo.');

        $from = Tenant::findOrFail($employee->tenant_id);
        $to = Tenant::where('status', 'active')->findOrFail($data['tenant_id']);
        abort_if($to->id === $from->id, 422, 'Elige una empresa distinta a la actual.');
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $to->id)->where('is_active', true)->findOrFail($data['branch_id']);
        $position = ! empty($data['position_id']) ? Position::withoutGlobalScopes()->where('tenant_id', $to->id)->findOrFail($data['position_id']) : null;
        $start = CarbonImmutable::parse($data['effective_date'])->startOfDay();
        $lastDay = $start->subDay();
        abort_if($employee->hire_date && $lastDay->lt($employee->hire_date), 422, 'La fecha de ingreso en la nueva empresa debe ser posterior a su ingreso actual.');

        $previous = $this->context->getOrNull();
        $files = [];
        try {
            $transfer = DB::transaction(function () use ($employee, $data, $actor, $from, $to, $branch, $position, $start, $lastDay, &$files) {
                $employee = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($employee->id);
                abort_unless($employee->status === 'active', 422, 'El colaborador ya no está activo.');
                $profile = EmployeeProfile::withoutGlobalScopes()->where('user_id', $employee->id)->first();
                $documents = ! empty($data['copy_documents'])
                    ? EmployeeDocument::withoutGlobalScopes()->where('user_id', $employee->id)
                        ->whereIn('type', self::PERSONAL_DOCUMENTS)->whereIn('status', ['approved', 'pending'])->get()
                    : collect();
                $email = $employee->email;

                // 1) Origen: cese, sin acceso; el correo se libera para el registro nuevo
                $this->context->set($from);
                $employee->forceFill([
                    'termination_date' => $lastDay->toDateString(), 'status' => 'inactive', 'fcm_token' => null,
                    'email' => $this->archivedEmail($email, $employee->id), 'remember_token' => Str::random(60),
                ])->save();
                $employee->tokens()->delete();
                $employee->endWebSessions();
                DB::table('branch_supervisors')->where('user_id', $employee->id)->delete();
                $this->audit->log('employee.transferred_out', $employee, ['name' => $employee->full_name, 'to' => $to->company_name, 'last_day' => $lastDay->toDateString()]);

                // 2) Destino: alta con sus datos personales y los de la nueva empresa; mismo acceso
                $this->context->set($to);
                [$new] = app(EmployeeProvisioner::class)->create($to, [
                    'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'email' => $email,
                    'document_type' => $employee->document_type, 'document_number' => $employee->document_number,
                    'phone' => $employee->phone, 'birth_date' => $employee->birth_date?->toDateString(), 'gender' => $employee->gender,
                    'role' => $data['role'], 'hire_date' => $start->toDateString(), 'contract_type' => $employee->contract_type,
                    'base_salary' => $data['base_salary'] ?? $employee->base_salary, 'bonus' => 0,
                    'position_id' => $position?->id, 'position' => $position?->name ?? $employee->position,
                    'department_id' => $position?->department_id,
                ], [$branch->id], $branch->id);
                // Conserva su contraseña (y si aún debía cambiarla); el registro de origen ya no entra
                $new->forceFill([
                    'password' => $employee->getAuthPassword(), 'must_change_password' => (bool) $employee->must_change_password,
                    'temp_password_expires_at' => $employee->must_change_password ? $employee->temp_password_expires_at : null,
                    'notification_preferences' => $employee->notification_preferences,
                ])->saveQuietly();

                if ($profile) {
                    $hasBank = filled($profile->bank_account_number) || filled($profile->interbank_code);
                    EmployeeProfile::create(['tenant_id' => $to->id, 'user_id' => $new->id] + $profile->only([
                        'address', 'district', 'emergency_contact_name', 'emergency_contact_phone',
                        'bank_name', 'bank_account_type', 'bank_account_currency', 'bank_account_number', 'interbank_code',
                        'pension_system', 'pension_fund', 'pension_code', 'health_insurance', 'family_allowance',
                        'cts_bank_name', 'cts_account_number', 'cts_currency',
                    ]) + ['payment_info_status' => $hasBank ? 'pending' : 'incomplete', 'payment_info_updated_at' => $hasBank ? now() : null]);
                }

                foreach ($documents as $doc) {
                    $copy = dirname($doc->path) . '/' . Str::uuid() . '.' . pathinfo($doc->path, PATHINFO_EXTENSION);
                    if (Storage::disk($doc->disk)->exists($doc->path) && Storage::disk($doc->disk)->copy($doc->path, $copy)) {
                        $files[] = [$doc->disk, $copy];
                        EmployeeDocument::create(['tenant_id' => $to->id, 'user_id' => $new->id, 'uploaded_by' => $actor->id] + $doc->only([
                            'type', 'title', 'disk', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'status', 'expires_at',
                        ]) + ['path' => $copy]);
                    }
                }

                if (empty($data['onboarding_required'])) {
                    // Ya trabajaba en el grupo: marca desde su primer día sin repetir la incorporación
                    OnboardingProcess::withoutGlobalScopes()->updateOrCreate(['user_id' => $new->id], [
                        'tenant_id' => $to->id, 'status' => OnboardingProcess::APPROVED, 'reviewed_by' => $actor->id, 'approved_at' => now(),
                    ]);
                    $new->forceFill(['onboarding_status' => OnboardingProcess::APPROVED])->saveQuietly();
                }

                $transfer = EmployeeTransfer::create([
                    'from_tenant_id' => $from->id, 'to_tenant_id' => $to->id, 'from_user_id' => $employee->id, 'to_user_id' => $new->id,
                    'effective_date' => $start->toDateString(), 'documents_copied' => count($files),
                    'onboarding_required' => ! empty($data['onboarding_required']), 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
                ]);
                $this->audit->log('employee.transferred_in', $new, ['name' => $new->full_name, 'from' => $from->company_name, 'start' => $start->toDateString()]);

                return $transfer;
            });
        } catch (\Throwable $e) {
            // Si algo falla no quedan copias sueltas de documentos
            foreach ($files as [$disk, $path]) {
                rescue(fn () => Storage::disk($disk)->delete($path), report: false);
            }
            throw $e;
        } finally {
            $previous ? $this->context->set($previous) : $this->context->clear();
        }

        return $transfer;
    }

    /** ana@gmail.com → ana+trasladado-15@gmail.com (el registro antiguo no inicia sesión; el correo queda libre) */
    private function archivedEmail(string $email, int $id): string
    {
        return Str::before($email, '@') . "+trasladado-{$id}@" . Str::after($email, '@');
    }
}
