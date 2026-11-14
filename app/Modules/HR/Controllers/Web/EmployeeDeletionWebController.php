<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\HR\Services\EmployeeDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Colaboradores › Eliminar colaborador (registro hecho por error). Permiso employees.delete; para
 * confirmar se escribe su número de documento. La lógica está en EmployeeDeletion.
 */
class EmployeeDeletionWebController extends Controller
{
    public function destroy(Request $request, int $id, EmployeeDeletion $deletion): RedirectResponse
    {
        $employee = User::forTenant(current_tenant_id_or_null())->whereNotNull('tenant_id')->findOrFail($id);
        $deletion->authorize($employee, $request->user());
        $typed = preg_replace('/\s+/', '', (string) $request->input('confirm_document'));
        $expected = preg_replace('/\s+/', '', (string) ($employee->document_number ?: $employee->employee_code));
        if ($expected === '' || strcasecmp($typed, $expected) !== 0) {
            throw ValidationException::withMessages(['confirm_document' => 'Escribe exactamente su número de documento para confirmar.'])
                ->errorBag('deleteEmployee');
        }

        $name = $employee->full_name;
        $deletion->delete($employee, $request->user());

        return redirect()->route('employees.index')->with('success', "{$name} fue eliminado con todo lo suyo. Queda constancia en la bitácora.");
    }
}
