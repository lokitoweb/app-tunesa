<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Attendance\Services\Reports\AttendanceReportService;
use App\Modules\Attendance\Services\Reports\HoursReportExporter;
use App\Modules\Attendance\Services\Reports\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Reporte de horas y faltas": vista previa paginada (reports.view) y
 * descargas Excel / PDF (reports.export). El supervisor solo ve a su equipo;
 * en la vista global del Super Admin se agrega la columna Empresa.
 */
class HoursReportWebController extends Controller
{
    public function __construct(
        private readonly AttendanceReportService $reports,
        private readonly HoursReportExporter $exporter,
    ) {}

    public function index(Request $request): View
    {
        $tenantId = current_tenant_id_or_null();
        $filters  = ReportFilters::fromRequest($request, $tenantId);

        $rows   = $this->reports->paginate($filters, 25);
        $totals = $this->reports->totals($filters);

        $branches  = Branch::forTenant($tenantId)->visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'tenant_id']);
        $employees = User::forTenant($tenantId)->whereIn('role', User::CLOCKING_ROLES)
            ->visibleTo($request->user())
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(500)
            ->get(['id', 'first_name', 'last_name', 'tenant_id']);

        return view('reports.hours', compact('filters', 'rows', 'totals', 'branches', 'employees'));
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $filters = ReportFilters::fromRequest($request, current_tenant_id_or_null());

        $path = tempnam(sys_get_temp_dir(), 'hrs') . '.xlsx';
        $this->exporter->xlsx($filters, $path);

        return response()->download($path, self::fileName($filters, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function pdf(Request $request): Response
    {
        $tenantId = current_tenant_id_or_null();
        $filters  = ReportFilters::fromRequest($request, $tenantId);
        $binary   = $this->exporter->pdf($filters, $tenantId ? Tenant::find($tenantId) : null);

        return response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . self::fileName($filters, 'pdf') . '"',
        ]);
    }

    /**
     * Reporte mensual automático (reports:send-monthly): enlace firmado de la
     * notificación. Además de la firma exige sesión, reports.export y que el
     * archivo sea de la empresa del usuario (el Super Admin ve cualquiera).
     */
    public function monthly(Request $request, int $tenant, string $period): Response
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || (int) $user->tenant_id === $tenant, 403);

        $path = self::monthlyPath($tenant, $period);
        abort_unless(Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->download($path, "horas-y-faltas_{$period}.xlsx");
    }

    public static function monthlyPath(int $tenantId, string $period): string
    {
        return "reports/{$tenantId}/horas-y-faltas_{$period}.xlsx";
    }

    private static function fileName(ReportFilters $f, string $ext): string
    {
        return "horas-y-faltas_{$f->from}_al_{$f->to}.{$ext}";
    }
}
