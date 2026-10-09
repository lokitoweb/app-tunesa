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
        return view('settings.mobile', ['config' => CompanyConfiguration::current()]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'required_documents'   => ['nullable', 'array'],
            'required_documents.*' => [Rule::in(array_keys(EmployeeDocument::TYPES))],
            'max_upload_mb'        => ['required', 'integer', 'between:1,20'],
        ]);

        // Un checkbox desmarcado no se envía: cada flag ausente es false
        $flags = collect(CompanyConfiguration::FLAGS)->keys()->mapWithKeys(fn ($f) => [$f => $request->boolean($f)])->all();

        $config = CompanyConfiguration::current();
        $config->update($flags + [
            'required_documents' => $validated['required_documents'] ?? [],
            'max_upload_mb'      => $validated['max_upload_mb'],
            'updated_by'         => Auth::id(),
        ]);
        $audit->log('company_configuration.updated', $config, $flags);

        return back()->with('success', 'Configuración de la app guardada. Los colaboradores la verán al refrescar.');
    }
}
