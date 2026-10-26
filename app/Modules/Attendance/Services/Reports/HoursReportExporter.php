<?php

namespace App\Modules\Attendance\Services\Reports;

use App\Modules\Core\Models\Tenant;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Exporta el "Reporte de horas y faltas" a Excel (.xlsx, PhpSpreadsheet) y PDF (Dompdf).
 *
 * Excel: hoja "Resumen" (un colaborador por fila) y hoja "Detalle diario"
 * (un día trabajado o con falta por fila). Encabezado con estilo y fijo,
 * celdas numéricas como números. Los colaboradores se recorren por bloques
 * (AttendanceReportService::each) para no cargar toda la empresa a la vez.
 */
class HoursReportExporter
{
    public function __construct(private readonly AttendanceReportService $reports) {}

    /** Reporte final del periodo (QA Ronda 2, 5.3). Duraciones en H:MM (número de Excel con formato [h]:mm, se puede sumar) */
    public const SUMMARY_HEADERS = [
        'Código', 'Colaborador', 'Cargo', 'Tipo doc.', 'Documento', 'Sucursal',
        'Días programados', 'Días trabajados', 'Horas trabajadas',
        'Tardanza', 'Días con tardanza', 'Salida anticipada',
        'HE diurnas', 'HE nocturnas', 'Horas nocturnas',
        'Faltas injustificadas', 'Faltas justificadas',
        'Descanso (días)', 'Feriados (días)', 'Vacaciones (días)', 'Permiso (días)', 'Descanso médico (días)',
        // Regla vigente 9: acumulado desde el ingreso al cierre del periodo
        'Vacaciones ganadas', 'Vacaciones usadas (total)', 'Saldo de vacaciones',
    ];

    /** Reporte por día y por colaborador (QA Ronda 2, 5.1 y 5.2): un día por fila con su estado */
    public const DETAIL_HEADERS = [
        'Código', 'Colaborador', 'Cargo', 'Fecha', 'Estado', 'Turno', 'Sucursal',
        'Entrada', 'Marcado entrada', 'Salida', 'Marcado salida', 'Descanso descontado (min)', 'Horas trabajadas',
        'Tardanza', 'Salida anticipada', 'HE diurnas', 'HE nocturnas', 'Horas nocturnas', 'Observación',
    ];

    /** Formato H:MM de Excel para duraciones (no se corta en 24 h) */
    private const DURATION_FORMAT = '[h]:mm';

    /** Escribe el .xlsx en $path */
    public function xlsx(ReportFilters $f, string $path): void
    {
        $withCompany = $f->tenantId === null;
        $companies   = $withCompany ? Tenant::pluck('company_name', 'id')->all() : [];

        $book = new Spreadsheet();
        $book->getProperties()->setTitle('Reporte de horas y faltas')->setCreator(config('app.name'));

        $summary = $book->getActiveSheet()->setTitle('Resumen');
        $detail  = $book->createSheet()->setTitle('Detalle diario');

        $prefix = $withCompany ? ['Empresa'] : [];
        $this->header($summary, array_merge($prefix, self::SUMMARY_HEADERS));
        $this->header($detail, array_merge($prefix, self::DETAIL_HEADERS));

        $sRow = $dRow = 2;
        foreach ($this->reports->each($f) as $report) {
            $company = $withCompany ? [$companies[$report['tenant_id']] ?? ''] : [];
            $this->row($summary, $sRow++, array_merge($company, self::summaryRow($report)));

            foreach ($report['days'] as $day) {
                $this->row($detail, $dRow++, array_merge($company, self::detailRow($report, $day)));
            }
        }

        foreach ([$summary, $detail] as $sheet) {
            foreach ($sheet->getColumnIterator() as $column) {
                $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            }
        }

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    /** PDF (A4 horizontal) con el resumen por colaborador; devuelve el binario */
    public function pdf(ReportFilters $f, ?Tenant $tenant): string
    {
        $withCompany = $f->tenantId === null;
        $companies   = $withCompany ? Tenant::pluck('company_name', 'id')->all() : [];

        $rows = [];
        $totals = AttendanceReportService::emptySummary();
        foreach ($this->reports->each($f) as $report) {
            $rows[] = ['company' => $companies[$report['tenant_id']] ?? null] + $report;
            foreach ($report['summary'] as $key => $value) {
                if (isset($totals[$key])) {
                    $totals[$key] += $value;
                }
            }
        }

        $html = view('reports.hours-pdf', [
            'rows'        => $rows,
            'totals'      => AttendanceReportService::roundSummary($totals),
            'filters'     => $f,
            'tenant'      => $tenant,
            'withCompany' => $withCompany,
            'generatedAt' => now(tenant_timezone()),
        ])->render();

        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        // Pie: "Página X de Y" en todas las páginas
        $canvas = $pdf->getCanvas();
        $font   = $pdf->getFontMetrics()->getFont('Helvetica');
        $canvas->page_text($canvas->get_width() - 120, $canvas->get_height() - 24, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.4, 0.4, 0.4]);

        return $pdf->output();
    }

    public static function summaryRow(array $r): array
    {
        $s = $r['summary'];

        return [
            $r['employee_code'] ?? '', $r['name'], $r['position'] ?? '', strtoupper((string) ($r['document_type'] ?? '')), $r['document_number'] ?? '', $r['branch'] ?? '',
            $s['scheduled_days'], $s['days_worked'], self::duration($s['worked_minutes']),
            self::duration($s['late_minutes']), $s['late_days'], self::duration($s['early_minutes']),
            self::duration($s['overtime_day_minutes']), self::duration($s['overtime_night_minutes']), self::duration($s['night_minutes']),
            $s['absences_unjustified'], $s['absences_justified'],
            $s['rest_days'], $s['holiday_days'], $s['vacation_days'], $s['permission_days'], $s['medical_leave_days'],
            // Sin fecha de ingreso o con régimen sin regla definida: no se calcula
            ...($r['vacation_balance'] ?? null
                ? [$r['vacation_balance']['earned'], $r['vacation_balance']['used'], $r['vacation_balance']['balance']]
                : ['—', '—', '—']),
        ];
    }

    public static function detailRow(array $r, array $d): array
    {
        return [
            $r['employee_code'] ?? '', $r['name'], $r['position'] ?? '', $d['date'],
            $d['day_status_label'] ?? '',
            $d['shift'] ?? '', $d['branch'] ?? '',
            $d['scheduled_in'] ?? '', $d['clock_in'] ?? '',
            $d['scheduled_out'] ?? '', $d['clock_out'] ?? ($d['open'] ? 'Sin salida' : ''),
            $d['break_minutes'], self::duration($d['worked_minutes']),
            self::duration($d['late_minutes']), self::duration($d['early_minutes']),
            self::duration($d['overtime_day_minutes']), self::duration($d['overtime_night_minutes']),
            self::duration($d['night_minutes']), $d['note'] ?? '',
        ];
    }

    /** Duración para la celda: minutos como fracción de día, que Excel muestra en H:MM */
    private static function duration(int $minutes): array
    {
        return ['duration' => $minutes];
    }

    private function header(Worksheet $sheet, array $headers): void
    {
        $sheet->fromArray($headers, null, 'A1');
        $last  = $sheet->getHighestColumn();
        $style = $sheet->getStyle("A1:{$last}1");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3C729E');
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}1");
    }

    /** Escribe una fila: números como números, texto siempre como texto (evita fórmulas inyectadas) */
    private function row(Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $i => $value) {
            $cell = [$i + 1, $row];
            if (is_array($value) && array_key_exists('duration', $value)) {
                $sheet->setCellValueExplicit($cell, $value['duration'] / 1440, DataType::TYPE_NUMERIC);
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::DURATION_FORMAT);
            } elseif (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
            }
        }
    }
}
