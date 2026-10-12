<?php

namespace App\Modules\Attendance\Services\Reports;

use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Calendario con semáforo en Excel: misma matriz que el panel (una fila por
 * colaborador, una columna por día con la letra y el color del estado, y los
 * totales al final), más una hoja con la leyenda.
 */
class CalendarExporter
{
    private const LEAD = ['Código', 'Colaborador', 'Cargo'];

    private const TOTALS = ['Faltas', 'Tardanzas', 'Min. tarde', 'Justificadas', 'Observadas', 'Horas'];

    /** Estados con texto claro sobre su color */
    private const LIGHT_TEXT = ['present', 'absent', 'excused'];

    public function __construct(private readonly AttendanceCalendarService $calendar) {}

    public function xlsx(CalendarFilters $f, string $path): void
    {
        $days = $this->calendar->days($f);

        $book = new Spreadsheet();
        $book->getProperties()->setTitle("Calendario de asistencia {$f->month}")->setCreator('TuneSA');
        $sheet = $book->getActiveSheet()->setTitle('Calendario ' . $f->month);

        $headers = array_merge(self::LEAD, array_map(fn ($d) => "{$d['weekday']} {$d['day']}", array_values($days)), self::TOTALS);
        foreach ($headers as $i => $text) {
            $sheet->setCellValue([$i + 1, 1], $text);
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('D2');

        $row = 2;
        $firstDay = count(self::LEAD) + 1;
        foreach ($this->calendar->each($f) as $r) {
            $sheet->setCellValue([1, $row], (string) $r['employee_code']);
            $sheet->setCellValue([2, $row], "{$r['last_name']}, {$r['first_name']}");
            $sheet->setCellValue([3, $row], (string) $r['position']);

            $col = $firstDay;
            foreach (array_keys($days) as $day) {
                $cell = $r['cells'][$day] ?? null;
                if ($cell) {
                    $this->paint($sheet, $col, $row, $cell);
                }
                $col++;
            }

            $t = $r['totals'];
            foreach ([$t['absent'], $t['late'], $t['late_minutes'], $t['excused'], $t['suspicious'], $t['worked_hours']] as $value) {
                $sheet->setCellValue([$col++, $row], $value);
            }
            $row++;
        }

        foreach ([1, 2, 3] as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
        for ($c = $firstDay; $c < $firstDay + count($days); $c++) {
            $sheet->getColumnDimensionByColumn($c)->setWidth(5);
        }
        if ($row > 2) {
            $sheet->getStyle(Coordinate::stringFromColumnIndex($firstDay) . "2:{$lastColumn}" . ($row - 1))
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $this->legend($book->createSheet()->setTitle('Leyenda'));

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    private function paint($sheet, int $col, int $row, array $cell): void
    {
        $text = $cell['letter'] . ($cell['suspicious'] ? '!' : '');
        $sheet->setCellValue([$col, $row], $text);

        $style = $sheet->getStyle([$col, $row]);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(AttendanceCalendarService::FILLS[$cell['state']]);
        if (in_array($cell['state'], self::LIGHT_TEXT, true)) {
            $style->getFont()->getColor()->setRGB('FFFFFF');
        }
        $style->getFont()->setBold(true);

        $note = array_filter([
            $cell['label'],
            $cell['clock_in'] ? "Entrada {$cell['clock_in']}" : null,
            $cell['clock_out'] ? "Salida {$cell['clock_out']}" : null,
            $cell['late_minutes'] ? "{$cell['late_minutes']} min tarde" : null,
            $cell['note'],
            $cell['suspicious'] ? 'Observada' : null,
        ]);
        $sheet->getComment([$col, $row])->getText()->createText(implode("\n", $note));
    }

    private function legend($sheet): void
    {
        $sheet->setCellValue('A1', 'Letra');
        $sheet->setCellValue('B1', 'Estado');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        $row = 2;
        foreach (AttendanceCalendarService::STATES as $state => [$letter, , $label]) {
            $this->paint($sheet, 1, $row, ['state' => $state, 'letter' => $letter, 'label' => $label, 'suspicious' => false,
                'clock_in' => null, 'clock_out' => null, 'late_minutes' => 0, 'note' => null]);
            $sheet->setCellValue("B{$row}", $label);
            $row++;
        }
        $sheet->setCellValue("A{$row}", '!');
        $sheet->setCellValue("B{$row}", 'Observada: la marcación tiene motivos de sospecha');
        $sheet->getColumnDimension('B')->setAutoSize(true);
    }
}
