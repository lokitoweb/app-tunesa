<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Services\PayslipImportService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $tenantId = current_tenant_id();

        $currentYear  = (int) $request->input('year', Carbon::now()->year);
        $currentMonth = (int) $request->input('month', Carbon::now()->month);

        $query = Payslip::where('tenant_id', $tenantId)
            ->where('period_year', $currentYear)
            ->where('period_month', $currentMonth)
            ->with(['user', 'tenant'])
            ->orderBy('created_at', 'desc');

        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $payslips = $query->paginate(20)->withQueryString();

        $employees = User::where('tenant_id', $tenantId)
            ->where('role', 'employee')
            ->orderBy('first_name')
            ->get();

        // Resumen del periodo
        $summary = [
            'total_emitted' => Payslip::where('tenant_id', $tenantId)->where('period_year', $currentYear)->where('period_month', $currentMonth)->count(),
            'published'     => Payslip::where('tenant_id', $tenantId)->where('period_year', $currentYear)->where('period_month', $currentMonth)->where('status', 'published')->count(),
            'read_count'    => Payslip::where('tenant_id', $tenantId)->where('period_year', $currentYear)->where('period_month', $currentMonth)->whereNotNull('read_at')->count(),
            'total_payroll' => Payslip::where('tenant_id', $tenantId)->where('period_year', $currentYear)->where('period_month', $currentMonth)->sum('net_salary'),
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
        $tenantId = current_tenant_id();

        $employees = User::where('tenant_id', $tenantId)
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
        $tenantId = current_tenant_id();

        $validated = $request->validate([
            'user_id'       => ['required', 'exists:users,id'],
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
        $tenantId = current_tenant_id();
        $payslip = Payslip::where('tenant_id', $tenantId)->findOrFail($id);

        $payslip->update([
            'status'       => 'published',
            'published_at' => Carbon::now(),
        ]);

        return back()->with('success', "Boleta publicada para {$payslip->user->first_name}.");
    }

    /**
     * Publicación masiva de todas las boletas en borrador del mes actual.
     */
    public function bulkPublish(Request $request): RedirectResponse
    {
        $tenantId = current_tenant_id();
        $year  = (int) $request->input('year');
        $month = (int) $request->input('month');

        $count = Payslip::where('tenant_id', $tenantId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', 'draft')
            ->update([
                'status'       => 'published',
                'published_at' => Carbon::now(),
            ]);

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
        $tenantId = current_tenant_id();
        $payslip = Payslip::where('tenant_id', $tenantId)->findOrFail($id);

        if (!Storage::disk('private')->exists($payslip->file_path)) {
            return back()->withErrors(['msg' => 'El archivo PDF no fue encontrado en el almacenamiento seguro.']);
        }

        return Storage::disk('private')->download($payslip->file_path, $payslip->file_name);
    }
}
