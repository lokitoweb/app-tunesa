<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use Illuminate\Support\Facades\URL;

/** A administradores y RRHH: el reporte mensual de horas y faltas está listo */
class MonthlyReportReady extends AppNotification
{
    /** Días que vale el enlace firmado guardado en la notificación */
    public const LINK_DAYS = 30;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $period,
    ) {}

    public function type(): string
    {
        return 'report.monthly_ready';
    }

    public function title(): string
    {
        return 'Reporte mensual de horas y faltas';
    }

    public function body(): string
    {
        [$year, $month] = explode('-', $this->period);

        return sprintf('Ya está disponible el reporte de %s/%s (Excel).', $month, $year);
    }

    public function payload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'period'    => $this->period,
            'url'       => self::signedUrl($this->tenantId, $this->period),
            'screen'    => 'reports',
        ];
    }

    public static function signedUrl(int $tenantId, string $period, int $days = self::LINK_DAYS): string
    {
        return URL::temporarySignedRoute('reports.monthly.download', now()->addDays($days), [
            'tenant' => $tenantId,
            'period' => $period,
        ]);
    }
}
