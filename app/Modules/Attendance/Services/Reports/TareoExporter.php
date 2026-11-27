<?php

namespace App\Modules\Attendance\Services\Reports;

use App\Modules\Core\Models\Tenant;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * «Tareo por colaborador» (Marcaciones y GPS), con el formato del Excel de tareo de Contabilidad
 * aprobado por el ingeniero el 26/11/2026: agrupado por colaborador, un renglón por cada día del periodo
 * (los días sin marcación con su motivo) y un resumen del periodo por colaborador.
 *
 * No calcula nada propio: todo sale de AttendanceReportService (las mismas reglas del reporte de horas,
 * la Matriz tareo y planilla). Decisiones del ingeniero:
 *  - Tardanza 2 (retorno del refrigerio) se muestra y se descuenta en planilla, como la de entrada.
 *  - Total HT y Total por semana = lo marcado real; aparte, las horas que se pagan (jornada + extras aprobadas).
 */
class TareoExporter
{
    public function __construct(private readonly AttendanceReportService $reports) {}

    public const HEADERS = [
        'Cargo', 'Sede', 'Colaborador', 'N.°', 'Día',
        'Marcación 1', 'Marcación 2', 'HT 1', 'Marcación 3', 'Marcación 4', 'HT 2', 'Total HT', 'Total por semana',
        'Hora ingreso 1', 'Tardanza 1', 'Hora ingreso 2', 'Tardanza 2', 'Total tardanza', 'Hora salida',
        'Horas extra', 'Estado horas extra', 'Total horas extra (aprobadas)', 'Horas que se pagan', 'Observación',
    ];

    /** Columnas (1 = A) que se combinan para el motivo de un día sin marcación: de Marcación 1 a Total HT */
    private const REASON_FROM = 6;

    private const REASON_TO = 12;

    /** Escribe el .xlsx en $path */
    public function xlsx(ReportFilters $f, string $path): void
    {
        $withCompany = $f->tenantId === null;
        $companies   = $withCompany ? Tenant::pluck('company_name', 'id')->all() : [];
        $offset      = $withCompany ? 1 : 0;

        $book = new Spreadsheet();
        $book->getProperties()->setTitle('Tareo por colaborador')->setCreator(config('app.name'));
        $sheet = $book->getActiveSheet()->setTitle('Tareo');
        HoursReportExporter::header($sheet, array_merge($withCompany ? ['Empresa'] : [], self::HEADERS));

        $r = 2;
        foreach ($this->reports->each($f) as $report) {
            $prefix = $withCompany ? [$companies[$report['tenant_id']] ?? ''] : [];
            $byDate = array_column($report['days'], null, 'date');
            $rows   = [];
            foreach (CarbonImmutable::parse($f->from)->daysUntil($f->to) as $date) {
                $rows[] = ['date' => CarbonImmutable::instance($date)] + ($byDate[$date->toDateString()] ?? []);
            }

            $weeks = [];
            foreach ($rows as $d) {
                $week = $d['date']->startOfWeek()->toDateString();
                $weeks[$week] = ($weeks[$week] ?? 0) + self::totalHt($d);
            }

            $totals = ['ht' => 0, 'late1' => 0, 'late2' => 0, 'ot' => 0, 'ot_ok' => 0, 'pay' => 0];
            foreach ($rows as $d) {
                $week = $d['date']->startOfWeek()->toDateString();
                $weekTotal = array_key_exists($week, $weeks) ? $weeks[$week] : null;
                unset($weeks[$week]); // en la primera fila de cada semana

                HoursReportExporter::row($sheet, $r, array_merge($prefix, $this->dayRow($report, $d, $weekTotal)));
                if (isset($d['day_status']) && ($d['mark1'] ?? null) === null) {
                    $this->reasonCells($sheet, $r, $offset, $d);
                }
                $r++;

                $totals['ht']    += self::totalHt($d);
                $totals['late1'] += $d['entry_late_minutes'] ?? 0;
                $totals['late2'] += $d['break_late_minutes'] ?? 0;
                $totals['ot']    += ($d['overtime_day_minutes'] ?? 0) + ($d['overtime_night_minutes'] ?? 0);
                $totals['ot_ok'] += $d['overtime_approved_minutes'] ?? 0;
                $totals['pay']   += $d['payable_minutes'] ?? 0;
            }

            HoursReportExporter::row($sheet, $r, array_merge($prefix, $this->summaryRow($report, $totals)));
            $style = $sheet->getStyle([1, $r, count(self::HEADERS) + $offset, $r]);
            $style->getFont()->setBold(true);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F2FF');
            $r += 2; // una fila en blanco entre colaboradores
        }

        foreach (range(1, count(self::HEADERS) + $offset) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    /** Un día del periodo (con o sin marcación) */
    private function dayRow(array $report, array $d, ?int $weekTotal): array
    {
        $date = $d['date'];
        $hm   = fn (?int $minutes) => $minutes ? HoursReportExporter::duration($minutes) : '';
        $marked = ($d['mark1'] ?? null) !== null;
        $overtime = ($d['overtime_day_minutes'] ?? 0) + ($d['overtime_night_minutes'] ?? 0);

        return [
            $report['position'] ?? '', $d['branch'] ?? $report['branch'] ?? '', self::name($report),
            $date->day, $date->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY'),
            $d['mark1'] ?? '', $d['mark2'] ?? '', $hm($d['ht1_minutes'] ?? 0),
            $d['mark3'] ?? '', $d['mark4'] ?? '', $hm($d['ht2_minutes'] ?? 0),
            $hm(self::totalHt($d)), $weekTotal === null ? '' : HoursReportExporter::duration($weekTotal),
            $marked ? ($d['scheduled_in'] ?? '') : '', $hm($d['entry_late_minutes'] ?? 0),
            $marked ? ($d['scheduled_break_in'] ?? '') : '', $hm($d['break_late_minutes'] ?? 0),
            $hm(($d['entry_late_minutes'] ?? 0) + ($d['break_late_minutes'] ?? 0)),
            $marked ? ($d['scheduled_out'] ?? '') : '',
            $hm($overtime), ($d['overtime_status'] ?? null) ? label('overtime_status', $d['overtime_status']) : '',
            $hm($d['overtime_approved_minutes'] ?? 0), $hm($d['payable_minutes'] ?? 0),
            trim(($marked && ($d['open'] ?? false) ? 'Sin salida. ' : '') . ($d['note'] ?? '')),
        ];
    }

    /** Resumen del periodo del colaborador: totales y, aparte, las horas que se pagan */
    private function summaryRow(array $report, array $t): array
    {
        $s = $report['summary'];
        $hm = fn (int $minutes) => HoursReportExporter::duration($minutes);

        return [
            $report['position'] ?? '', $report['branch'] ?? '', self::name($report), '', 'Resumen del periodo',
            '', '', '', '', '', '', $hm($t['ht']), '',
            '', $hm($t['late1']), '', $hm($t['late2']), $hm($t['late1'] + $t['late2']), '',
            $hm($t['ot']), '', $hm($t['ot_ok']), $hm($t['pay']),
            "Días trabajados: {$s['days_worked']} · Faltas injustificadas: {$s['absences_unjustified']} · Faltas justificadas: {$s['absences_justified']}"
            . " · Descansos: {$s['rest_days']} · Feriados: {$s['holiday_days']} · Vacaciones: {$s['vacation_days']}",
        ];
    }

    /** Día sin marcación: el motivo (FERIADO, DESCANSO, VACACIONES, FALTA...) a lo ancho de las marcaciones */
    private function reasonCells(Worksheet $sheet, int $r, int $offset, array $d): void
    {
        [$from, $to] = [self::REASON_FROM + $offset, self::REASON_TO + $offset];
        $sheet->setCellValue([$from, $r], mb_strtoupper((string) ($d['day_status_label'] ?? '')));
        $sheet->mergeCells([$from, $r, $to, $r]);
        $style = $sheet->getStyle([1, $r, count(self::HEADERS) + $offset, $r]);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(($d['day_status'] ?? '') === 'absent' ? 'FDE2E2' : 'FFF7E6');
        $sheet->getStyle([$from, $r, $to, $r])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle([$from, $r, $to, $r])->getFont()->setBold(true);
    }

    /** Total HT del día: lo marcado real (HT 1 + HT 2) */
    private static function totalHt(array $d): int
    {
        return ($d['ht1_minutes'] ?? 0) + ($d['ht2_minutes'] ?? 0);
    }

    private static function name(array $report): string
    {
        return trim(($report['last_name'] ?? '') . ', ' . ($report['first_name'] ?? ''), ', ');
    }
}
