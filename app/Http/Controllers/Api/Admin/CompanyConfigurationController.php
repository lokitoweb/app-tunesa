<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyConfiguration;
use App\Models\EmployeeDocument;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Panel de visibilidad: qué módulos ve y edita el colaborador en la app.
 */
class CompanyConfigurationController extends Controller
{
    /** GET /api/admin/company-configuration */
    public function show(): JsonResponse
    {
        $config = CompanyConfiguration::current();

        return response()->json(['success' => true, 'data' => $config->only(array_keys(CompanyConfiguration::FLAGS)) + [
            'required_documents' => $config->requiredDocuments(),
            'max_upload_mb'      => $config->max_upload_mb,
            'geofence_policy'    => $config->geofence_policy,
        ]]);
    }

    /** PUT /api/admin/company-configuration */
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $rules = collect(CompanyConfiguration::FLAGS)->mapWithKeys(fn ($_, $flag) => [$flag => ['sometimes', 'boolean']])->all();

        $validated = $request->validate($rules + [
            'required_documents'   => ['sometimes', 'array'],
            'required_documents.*' => [Rule::in(array_keys(EmployeeDocument::TYPES))],
            'max_upload_mb'        => ['sometimes', 'integer', 'between:1,20'],
            'geofence_policy'      => ['sometimes', Rule::in(array_keys(CompanyConfiguration::GEOFENCE_POLICIES))],
        ]);

        $config = CompanyConfiguration::current();
        $audit->updateWithAudit('company_configuration.updated', $config, $validated + ['updated_by' => $request->user()->id]);

        return $this->show();
    }
}
