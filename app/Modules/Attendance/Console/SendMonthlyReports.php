<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Controllers\Web\HoursReportWebController;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Attendance\Notifications\MonthlyReportReady;
use App\Modules\Attendance\Services\Reports\HoursReportExporter;
use App\Modules\Attendance\Services\Reports\ReportFilters;
use App\Modules\Core\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reporte mensual automático de horas y faltas.
 *
 * Para cada empresa activa genera el Excel del mes anterior (en la zona
 * horaria de la empresa), lo guarda en el disco privado
 * (reports/{tenant}/horas-y-faltas_YYYY-MM.xlsx) y avisa con una notificación
 * in-app a administradores y RRHH, con enlace firmado de descarga.
 * Si una empresa falla, se registra y se sigue con la siguiente.
 *
 *   php artisan reports:send-monthly
 *   php artisan reports:send-monthly --month=2026-09 --tenant=empresa-demo
 */
class SendMonthlyReports extends Command
{
    protected $signature = 'reports:send-monthly
                            {--month= : Mes a generar (YYYY-MM). Por defecto: el mes anterior}
                            {--tenant= : Slug de una empresa concreta}';

    protected $description = 'Genera el Excel mensual de horas y faltas por empresa y notifica a administradores y RRHH';

    public function handle(TenantContext $context, HoursReportExporter $exporter): int
    {
        $month = $this->option('month');
        if ($month !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('--month debe tener el formato YYYY-MM.');

            return self::INVALID;
        }

        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')
            ->get();

        $failures = 0;

        foreach ($tenants as $tenant) {
            $context->set($tenant);

            try {
                $start  = $month
                    ? Carbon::createFromFormat('Y-m-d', "{$month}-01", $tenant->timezone)
                    : Carbon::now($tenant->timezone)->subMonthNoOverflow()->startOfMonth();
                $period = $start->format('Y-m');

                $filters = new ReportFilters($tenant->id, $start->toDateString(), $start->copy()->endOfMonth()->toDateString());

                $tmp = tempnam(sys_get_temp_dir(), 'mrep');
                $exporter->xlsx($filters, $tmp);

                $path = HoursReportWebController::monthlyPath($tenant->id, $period);
                Storage::disk('private')->put($path, fopen($tmp, 'rb'));
                @unlink($tmp);

                $recipients = User::where('tenant_id', $tenant->id)
                    ->whereIn('role', ['company_admin', 'hr_manager'])
                    ->where('status', 'active')
                    ->get();

                Notification::send($recipients, new MonthlyReportReady($tenant->id, $period));

                $this->info("{$tenant->slug} {$period}: reporte guardado en {$path}; {$recipients->count()} notificados.");
            } catch (Throwable $e) {
                $failures++;
                report($e);
                $this->error("{$tenant->slug}: no se pudo generar el reporte ({$e->getMessage()}).");
            } finally {
                $context->clear();
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
