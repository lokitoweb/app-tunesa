<?php

namespace App\Services\Reports;

use App\Models\Tenant;
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

    public const SUMMARY_HEADERS = [
        'Código', 'Colaborador', 'Documento', 'Sucursal',
        'Días programados', 'Días trabajados', 'Horas trabajadas',
        'Tardanza (min)', 'Días con tardanza', 'Salida anticipada (min)',
        'HE diurnas (h)', 'HE nocturnas (h)', 'Horas nocturnas (h)',
        'Faltas injustificadas', 'Faltas justificadas',
    ];

    public const DETAIL_HEADERS = [
        'Código', 'Colaborador', 'Fecha', 'Estado', 'Turno', 'Sucursal',
        'Entrada', 'Salida', 'Descanso descontado (min)', 'Horas trabajadas',
        'Tardanza (min)', 'Salida anticipada (min)', 'HE diurnas (h)', 'HE nocturnas (h)',
        'Horas nocturnas (h)', 'Observación',
    ];

    /** Escribe el .xlsx en $path */
    public function xlsx(ReportFilters $f, string $path): void
    {
        $withCompany = $f->tenantId === null;
        $companies   = $withCompany ? Tenant::pluck('company_name', 'id')->all() : [];

        $book = new Spreadsheet();
        $book->getProperties()->setTitle('Reporte de horas y faltas')->setCreator('TuneSA');

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
            $r['employee_code'] ?? '', $r['name'], $r['document_number'] ?? '', $r['branch'] ?? '',
            $s['scheduled_days'], $s['days_worked'], $s['worked_hours'],
            $s['late_minutes'], $s['late_days'], $s['early_minutes'],
            $s['overtime_day_hours'], $s['overtime_night_hours'], $s['night_hours'],
            $s['absences_unjustified'], $s['absences_justified'],
        ];
    }

    public static function detailRow(array $r, array $d): array
    {
        return [
            $r['employee_code'] ?? '', $r['name'], $d['date'],
            AttendanceReportService::STATUS_LABELS[$d['status']] ?? $d['status'],
            $d['shift'] ?? '', $d['branch'] ?? '',
            $d['clock_in'] ?? '', $d['clock_out'] ?? ($d['open'] ? 'Sin salida' : ''),
            $d['break_minutes'], round($d['worked_minutes'] / 60, 2),
            $d['late_minutes'], $d['early_minutes'],
            round($d['overtime_day_minutes'] / 60, 2), round($d['overtime_night_minutes'] / 60, 2),
            round($d['night_minutes'] / 60, 2), $d['note'] ?? '',
        ];
    }

    private function header(Worksheet $sheet, array $headers): void
    {
        $sheet->fromArray($headers, null, 'A1');
        $last  = $sheet->getHighestColumn();
        $style = $sheet->getStyle("A1:{$last}1");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E40AF');
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
            if (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
            }
        }
    }
}
