<?php

namespace App\Modules\Payroll\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipImportService;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PayslipWebController (Web)
 *
 * Módulo de RRHH para carga de boletas de pago en PDF,
 * cálculo de periodo, publicación y auditoría de visualización por empleado.
 */
class PayslipWebController extends Controller
{
    /**
     * Listado de boletas de pago por periodo mensual.
     */
    public function index(Request $request): View
    {
        $tenantId = current_tenant_id_or_null();

        $currentYear  = (int) $request->input('year', Carbon::now()->year);
        $currentMonth = (int) $request->input('month', Carbon::now()->month);

        $query = Payslip::forTenant($tenantId)
            ->where('period_year', $currentYear)
            ->where('period_month', $currentMonth)
            ->with(['employee', 'tenant'])
            ->orderBy('created_at', 'desc');

        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $payslips = $query->paginate(20)->withQueryString();

        $employees = User::forTenant($tenantId)
            ->where('role', 'employee')
            ->orderBy('first_name')
            ->get();

        // Resumen del periodo
        $summary = [
            'total_emitted' => Payslip::forTenant($tenantId)->where('period_year', $currentYear)->where('period_month', $currentMonth)->count(),
            'published'     => Payslip::forTenant($tenantId)->where('period_year', $currentYear)->where('period_month', $currentMonth)->where('status', 'published')->count(),
            'read_count'    => Payslip::forTenant($tenantId)->where('period_year', $currentYear)->where('period_month', $currentMonth)->whereNotNull('read_at')->count(),
            'total_payroll' => Payslip::forTenant($tenantId)->where('period_year', $currentYear)->where('period_month', $currentMonth)->sum('net_salary'),
        ];

        return view('payslips.index', compact(
            'payslips',
            'employees',
            'currentYear',
            'currentMonth',
            'summary'
        ));
    }

    /**
     * Formulario para subir una nueva boleta de pago individual.
     */
    public function create(): View
    {
        $tenantId = current_tenant_id_or_null();

        $employees = User::forTenant($tenantId)
            ->where('role', 'employee')
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get();

        return view('payslips.create', compact('employees'));
    }

    /**
     * Almacena el PDF de la boleta y sus conceptos desglosados.
     */
    public function store(Request $request): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();

        $validated = $request->validate([
            'user_id'       => ['required', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'title'         => ['required', 'string', 'max:150'],
            'period_month'  => ['required', 'integer', 'between:1,12'],
            'period_year'   => ['required', 'integer', 'between:2020,2035'],
            'pdf_file'      => ['required', 'file', 'mimes:pdf', 'max:10240'], // Max 10MB
            'gross_salary'  => ['required', 'numeric', 'min:0'],
            'deductions'    => ['required', 'numeric', 'min:0'],
            'net_salary'    => ['required', 'numeric', 'min:0'],
            'publish_now'   => ['nullable', 'boolean'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        // Una boleta por colaborador y periodo (índice unique_payslip_per_period)
        $duplicate = Payslip::withTrashed()->where('user_id', $validated['user_id'])
            ->where('period_month', $validated['period_month'])
            ->where('period_year', $validated['period_year'])
            ->exists();
        if ($duplicate) {
            return back()->withInput()->withErrors(['user_id' => 'Ese colaborador ya tiene una boleta para ese periodo.']);
        }

        $file = $request->file('pdf_file');
        $fileName = sprintf(
            'payslip_%d_%d_%d_%s.pdf',
            $tenantId,
            $validated['user_id'],
            $validated['period_month'],
            uniqid()
        );

        $path = $file->storeAs("payslips/{$tenantId}/{$validated['period_year']}", $fileName, 'private');

        $publishNow = $request->boolean('publish_now', false);

        Payslip::create([
            'tenant_id'    => $tenantId,
            'user_id'      => $validated['user_id'],
            'uploaded_by'  => Auth::id(),
            'title'        => $validated['title'],
            'period_month' => $validated['period_month'],
            'period_year'  => $validated['period_year'],
            'file_path'    => $path,
            'file_name'    => $file->getClientOriginalName(),
            'file_size'    => $file->getSize(),
            'mime_type'    => 'application/pdf',
            'gross_salary' => $validated['gross_salary'],
            'deductions'   => $validated['deductions'],
            'net_salary'   => $validated['net_salary'],
            'status'       => $publishNow ? 'published' : 'draft',
            'published_at' => $publishNow ? Carbon::now() : null,
            'notes'        => $validated['notes'] ?? null,
        ]);

        return redirect()->route('payslips.index', [
            'year'  => $validated['period_year'],
            'month' => $validated['period_month'],
        ])->with('success', 'Boleta de pago cargada exitosamente.');
    }

    /**
     * Publica una boleta para que el colaborador pueda verla en su móvil.
     */
    public function publish(int $id): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();
        $payslip = Payslip::forTenant($tenantId)->findOrFail($id);

        $payslip->update([
            'status'       => 'published',
            'published_at' => Carbon::now(),
        ]);

        return back()->with('success', "Boleta publicada para {$payslip->employee?->first_name}.");
    }

    /**
     * Elimina una boleta no publicada (borrador o anulada) junto con su PDF,
     * para poder volver a subir la del mismo periodo. Igual que
     * DELETE /api/admin/payslips/{id}: una publicada no se borra, se anula.
     */
    public function destroy(int $id): RedirectResponse
    {
        $payslip = Payslip::forTenant(current_tenant_id_or_null())->findOrFail($id);

        if ($payslip->isPublished()) {
            return back()->with('error', 'La boleta ya fue publicada: anúlala en lugar de eliminarla.');
        }

        Storage::disk('private')->delete($payslip->file_path);
        $payslip->forceDelete();

        app(AuditLogger::class)->log('payslip.deleted', $payslip, [
            'employee_id' => $payslip->user_id,
            'period'      => sprintf('%04d-%02d', $payslip->period_year, $payslip->period_month),
        ]);

        return back()->with('success', 'Boleta eliminada. Ya puedes volver a subir la de ese periodo.');
    }

    /**
     * Anula una boleta publicada (status archived): deja de verse en la app
     * pero queda el registro. Equivale a PUT /api/admin/payslips/{id} status=archived.
     */
    public function annul(int $id): RedirectResponse
    {
        $payslip = Payslip::forTenant(current_tenant_id_or_null())->findOrFail($id);

        if (! $payslip->isPublished()) {
            return back()->with('error', 'Solo se anulan boletas publicadas. Un borrador se puede eliminar.');
        }

        $payslip->update(['status' => 'archived']);

        app(AuditLogger::class)->log('payslip.archived', $payslip, [
            'employee_id' => $payslip->user_id,
            'period'      => sprintf('%04d-%02d', $payslip->period_year, $payslip->period_month),
        ]);

        return back()->with('success', "Boleta de {$payslip->employee?->first_name} anulada: ya no se ve en la app.");
    }

    /**
     * Publicación masiva de todas las boletas en borrador del mes actual.
     */
    public function bulkPublish(Request $request): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();
        $year  = (int) $request->input('year');
        $month = (int) $request->input('month');

        // Una por una (no update masivo) para que cada colaborador reciba su notificación
        $drafts = Payslip::forTenant($tenantId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', 'draft')
            ->get();

        foreach ($drafts as $payslip) {
            $payslip->update([
                'status'       => 'published',
                'published_at' => Carbon::now(),
            ]);
        }
        $count = $drafts->count();

        return back()->with('success', "Se publicaron {$count} boletas de pago del periodo {$month}/{$year}.");
    }

    /**
     * Formulario de carga masiva de boletas PDF.
     */
    public function bulkCreate(): View
    {
        return view('payslips.bulk', [
            'result' => session('import_result'),
        ]);
    }

    /**
     * Carga masiva: varios PDFs de un período, asignados por DNI o código
     * de empleado en el nombre del archivo.
     */
    public function bulkStore(Request $request, PayslipImportService $importer): RedirectResponse
    {
        $validated = $request->validate([
            'period_month' => ['required', 'integer', 'between:1,12'],
            'period_year'  => ['required', 'integer', 'between:2020,2100'],
            'files'        => ['required', 'array', 'min:1', 'max:500'],
            'files.*'      => ['file', 'mimes:pdf', 'max:10240'],
            'publish_now'  => ['nullable', 'boolean'],
        ]);

        $result = $importer->import(
            $request->file('files'),
            (int) $validated['period_month'],
            (int) $validated['period_year'],
            Auth::id(),
            $request->boolean('publish_now'),
        );

        $ok = count($result['created']) + count($result['replaced']);

        return redirect()->route('payslips.bulk')
            ->with('import_result', $result)
            ->with($ok > 0 ? 'success' : 'error', "Se cargaron {$ok} boletas; " . count($result['skipped']) . ' archivo(s) omitido(s).');
    }

    /**
     * Vista previa del PDF en el navegador (inline), sin URL pública.
     */
    public function view(int $id)
    {
        $payslip = Payslip::findOrFail($id);

        abort_unless(Storage::disk('private')->exists($payslip->file_path), 404);

        return response()->file(Storage::disk('private')->path($payslip->file_path), [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . addslashes($payslip->file_name) . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /**
     * Descarga segura de la boleta PDF verificando tenant.
     */
    public function download(int $id)
    {
        $tenantId = current_tenant_id_or_null();
        $payslip = Payslip::forTenant($tenantId)->findOrFail($id);

        if (!Storage::disk('private')->exists($payslip->file_path)) {
            return back()->withErrors(['msg' => 'El archivo PDF no fue encontrado en el almacenamiento seguro.']);
        }

        return Storage::disk('private')->download($payslip->file_path, $payslip->file_name);
    }
}
