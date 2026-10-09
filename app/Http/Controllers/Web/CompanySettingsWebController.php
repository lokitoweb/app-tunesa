<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CompanyConfiguration;
use App\Models\EmployeeDocument;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Panel de control de visibilidad: qué ve y edita el colaborador en la app.
 */
class CompanySettingsWebController extends Controller
{
    public function edit(): View
    {
        // Vista global del Super Admin: la configuración es por empresa
        $config = current_tenant_id_or_null() ? CompanyConfiguration::current() : null;

        return view('settings.mobile', compact('config'));
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'required_documents'   => ['nullable', 'array'],
            'required_documents.*' => [Rule::in(array_keys(EmployeeDocument::TYPES))],
            'max_upload_mb'        => ['required', 'integer', 'between:1,20'],
            'geofence_policy'      => ['sometimes', Rule::in(array_keys(CompanyConfiguration::GEOFENCE_POLICIES))],
        ]);

        // Un checkbox desmarcado no se envía: cada flag ausente es false
        $flags = collect(CompanyConfiguration::FLAGS)->keys()->mapWithKeys(fn ($f) => [$f => $request->boolean($f)])->all();

        $config = CompanyConfiguration::current();
        $validated['geofence_policy'] ??= $config->geofence_policy;
        $config->update($flags + [
            'required_documents' => $validated['required_documents'] ?? [],
            'max_upload_mb'      => $validated['max_upload_mb'],
            'geofence_policy'    => $validated['geofence_policy'],
            'updated_by'         => Auth::id(),
        ]);
        $audit->log('company_configuration.updated', $config, $flags + ['geofence_policy' => $validated['geofence_policy']]);

        return back()->with('success', 'Configuración de la app guardada. Los colaboradores la verán al refrescar.');
    }
}
