<?php

namespace App\Modules\Attendance\Services\Reports;

use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use Illuminate\Support\Facades\DB;
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
    private const LEAD = ['Código', 'Colaborador', 'Agencia', 'Cargo'];

    private const TOTALS = ['Faltas', 'Tardanzas', 'Tardanza', 'Justificadas', 'Observadas', 'Horas de jornada', 'HE aprobadas', 'HE por aprobar'];

    /** Duraciones como tiempo de Excel (fracción de día) con formato [hh]:mm:ss: se ven como 00:12:00 o 31:40:12 y se pueden sumar */
    private const DURATION_FORMAT = '[hh]:mm:ss';

    /** Estados con texto claro sobre su color */
    private const LIGHT_TEXT = ['present', 'absent', 'excused'];

    public function __construct(private readonly AttendanceCalendarService $calendar) {}

    public function xlsx(CalendarFilters $f, string $path): void
    {
        $days = $this->calendar->days($f);

        $book = new Spreadsheet();
        $book->getProperties()->setTitle("Calendario de asistencia " . $f->periodLabel())->setCreator(config('app.name'));
        $sheet = $book->getActiveSheet()->setTitle($f->from ? 'Calendario' : 'Calendario ' . $f->month);

        $headers = array_merge(self::LEAD, array_map(fn ($d) => "{$d['weekday']} {$d['day']}", array_values($days)), self::TOTALS);
        foreach ($headers as $i => $text) {
            $sheet->setCellValue([$i + 1, 1], $text);
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane(Coordinate::stringFromColumnIndex(count(self::LEAD) + 1) . '2');

        $row = 2;
        $firstDay = count(self::LEAD) + 1;
        // De a 200 filas: las agencias de cada bloque salen en una sola consulta
        foreach ($this->chunks($this->calendar->each($f), 200) as $block) {
            $agencies = $this->agencies($f, array_column($block, 'user_id'));
            foreach ($block as $r) {
                $sheet->setCellValue([1, $row], (string) $r['employee_code']);
                $sheet->setCellValue([2, $row], "{$r['last_name']}, {$r['first_name']}");
                $sheet->setCellValue([3, $row], $agencies[$r['user_id']] ?? '');
                $sheet->setCellValue([4, $row], (string) $r['position']);

                $col = $firstDay;
                foreach (array_keys($days) as $day) {
                    $cell = $r['cells'][$day] ?? null;
                    if ($cell) {
                        $this->paint($sheet, $col, $row, $cell);
                    }
                    $col++;
                }

                $t = $r['totals'];
                foreach ([$t['absent'], $t['late']] as $value) {
                    $sheet->setCellValue([$col++, $row], $value);
                }
                $this->duration($sheet, $col++, $row, $t['late_minutes'] * 60);
                foreach ([$t['excused'], $t['suspicious']] as $value) {
                    $sheet->setCellValue([$col++, $row], $value);
                }
                foreach ([$t['worked_hours'], $t['overtime_approved'], $t['overtime_pending']] as $hours) {
                    $this->duration($sheet, $col++, $row, \App\Modules\Attendance\Models\Attendance::minutes($hours) * 60);
                }
                $row++;
            }
        }

        foreach ([1, 2, 3, 4] as $c) {
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

    /**
     * Agencia de cada persona, según el filtro: la elegida; con zona, sus agencias de esa zona; sin filtro,
     * todas las suyas (la principal primero). Misma regla con la que el calendario filtra por agencia.
     *
     * @param  list<int>  $userIds
     * @return array<int, string>  user_id => "Agencia A, Agencia B"
     */
    private function agencies(CalendarFilters $f, array $userIds): array
    {
        return DB::table('employee_branch')
            ->join('branches', 'branches.id', '=', 'employee_branch.branch_id')
            ->whereIn('employee_branch.user_id', $userIds)
            ->whereNull('branches.deleted_at')
            ->when($f->branchId, fn ($q, $id) => $q->where('branches.id', $id))
            ->when($f->zoneId, fn ($q, $id) => $q->where('branches.zone_id', $id))
            ->orderByDesc('employee_branch.is_primary')->orderBy('branches.name')
            ->get(['employee_branch.user_id', 'branches.name'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('name')->unique()->implode(', '))
            ->all();
    }

    private function duration($sheet, int $col, int $row, int|float $seconds): void
    {
        $sheet->setCellValue([$col, $row], max(0, (int) round($seconds)) / 86400);
        $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode(self::DURATION_FORMAT);
    }

    /** Agrupa un generador en bloques de $size sin cargarlo entero */
    private function chunks(iterable $rows, int $size): \Generator
    {
        $block = [];
        foreach ($rows as $r) {
            $block[] = $r;
            if (count($block) === $size) {
                yield $block;
                $block = [];
            }
        }
        if ($block) {
            yield $block;
        }
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
            match ($cell['overtime_status'] ?? null) {
                'pending'  => 'Horas extra por aprobar: ' . horas_hms($cell['overtime_hours']),
                'approved' => 'Horas extra aprobadas: ' . horas_hms($cell['overtime_hours']),
                'rejected' => 'Horas extra rechazadas',
                default    => null,
            },
        ]);
        $sheet->getComment([$col, $row])->getText()->createText(implode("\n", $note));
    }

    private function legend($sheet): void
    {
        $sheet->setCellValue('A1', 'Letra');
        $sheet->setCellValue('B1', 'Estado');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        $row = 2;
        foreach (AttendanceCalendarService::states() as $state => [$letter, , $label]) {
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
