<?php

namespace App\Modules\Attendance\Services\Reports;

use App\Modules\Attendance\Models\TripLog;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Hojas de conducción para firmar (Bitácora de viajes, diseño del ingeniero de Tunesa), en Excel:
 * una hoja «Conducción de ida» y otra «Conducción de regreso». Por viaje: N.º de salida, ruta, placa,
 * Conductor 1, Conductor 2, Auxiliar de carga, salida, llegada, horas de conducción y una firma por
 * cada integrante. Cabecera con el logo y el color de la empresa; firmas del supervisor y de RR. HH. al pie.
 */
class TripSheetExporter
{
    public const GROUPS = [['N°', 1], ['VIAJE', 3], ['TRIPULACIÓN', 3], ['TRAMO', 3], ['FIRMAS', 3]];

    public const COLUMNS = ['N° SALIDA', 'RUTA', 'PLACA', 'CONDUCTOR 1', 'CONDUCTOR 2', 'AUXILIAR DE CARGA',
        'SALIDA', 'LLEGADA', 'HORAS DE CONDUCCIÓN', 'CONDUCTOR 1', 'CONDUCTOR 2', 'AUXILIAR'];

    /** Filas mínimas de cada hoja: las que sobran quedan en blanco para llenarlas a mano */
    private const MIN_ROWS = 12;

    /** Primera fila del encabezado de la tabla */
    public const HEAD = 6;

    private const LEG_COLORS = ['ida' => '1D4ED8', 'regreso' => 'B45309'];
    private const DARK = '374151';
    private const GRID = 'BFC5CD';

    /** @param Collection<int, TripLog> $trips */
    public function xlsx(Collection $trips, ?Tenant $tenant, string $from, string $to, string $path): void
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $book->getProperties()->setTitle('Hojas de conducción')->setCreator(config('app.name'));

        $this->sheet($book->getActiveSheet()->setTitle('Conducción de ida'), 'ida', $trips, $tenant, $from, $to);
        $this->sheet($book->createSheet()->setTitle('Conducción de regreso'), 'regreso', $trips, $tenant, $from, $to);

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    /** @param Collection<int, TripLog> $trips */
    private function sheet(Worksheet $sheet, string $leg, Collection $trips, ?Tenant $tenant, string $from, string $to): void
    {
        $sheet->setShowGridlines(false);
        $brand = ltrim((string) ($tenant?->primary_color ?: '#3C729E'), '#');
        $color = self::LEG_COLORS[$leg];
        $h = self::HEAD;
        $first = $h + 2;
        $last = 'M';

        $this->title($sheet, $tenant, $brand, $leg, $from, $to);
        $this->tableHead($sheet, $leg, $color);

        $nm = fn (?User $u) => $u ? "{$u->last_name}, {$u->first_name}" : '';
        $at = fn ($d, $t) => $d && $t ? Carbon::parse($d)->format('d/m/Y') . ' ' . substr((string) $t, 0, 5) : '';

        $row = $first;
        foreach ($trips->values() as $n => $trip) {
            [$out, $in, $minutes, $route] = $leg === 'ida'
                ? [$at($trip->trip_date, $trip->departure_time), $at($trip->arrival_date, $trip->arrival_time), $trip->outboundMinutes(), $trip->route]
                : [$at($trip->return_departure_date, $trip->return_departure_time), $at($trip->return_date, $trip->return_time), $trip->returnMinutes(), $trip->return_route ?: $trip->route];
            $values = [$n + 1, (string) $trip->trip_number, $route, $trip->vehicle_plate,
                $nm($trip->driver), $nm($trip->coDriver), $nm($trip->helper), $out, $in, $minutes === null ? '' : minutos_hms($minutes)];
            foreach ($values as $i => $value) {
                $sheet->setCellValueExplicit([$i + 1, $row], (string) $value, $i === 0 ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            $row++;
        }
        $lastRow = max($row - 1, $first + self::MIN_ROWS - 1);
        for ($r = $row; $r <= $lastRow; $r++) {
            $sheet->setCellValue("A{$r}", $r - $first + 1);
        }

        $body = $sheet->getStyle("A{$first}:{$last}{$lastRow}");
        $body->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::GRID);
        $body->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        foreach (['A', 'B', 'D', 'H', 'I', 'J'] as $col) {
            $sheet->getStyle("{$col}{$first}:{$col}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle("J{$first}:J{$lastRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$first}:A{$lastRow}")->getFont()->setSize(9)->getColor()->setRGB('6B7280');
        for ($r = $first; $r <= $lastRow; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(34);
        }
        // Contorno y separación marcada entre bloques
        $sheet->getStyle("A{$h}:{$last}{$lastRow}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::DARK);
        foreach (['B', 'E', 'H', 'K'] as $col) {
            $sheet->getStyle("{$col}{$h}:{$col}{$lastRow}")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::DARK);
        }

        $this->signatures($sheet, $lastRow + 3);

        foreach (['A' => 5, 'B' => 9, 'C' => 22, 'D' => 10, 'E' => 22, 'F' => 22, 'G' => 22, 'H' => 15, 'I' => 15, 'J' => 11, 'K' => 16, 'L' => 16, 'M' => 16] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setHorizontalCentered(true)
            ->setRowsToRepeatAtTopByStartAndEnd($h, $h + 1)->setPrintArea("A1:{$last}" . ($lastRow + 6));
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.3)->setRight(0.3);
        $sheet->getHeaderFooter()->setOddFooter('&L&8' . str_replace('&', '&&', (string) ($tenant?->company_name ?? '')) . ' · Hoja de conducción de ' . $leg . '&R&8Página &P de &N');
        $sheet->freezePane('A' . $first);
    }

    private function title(Worksheet $sheet, ?Tenant $tenant, string $brand, string $leg, string $from, string $to): void
    {
        $dmy = fn ($d) => Carbon::parse($d)->format('d/m/Y');
        $logo = $tenant?->logo_path && Storage::disk('public')->exists($tenant->logo_path)
            && in_array(strtolower(pathinfo($tenant->logo_path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true);
        $text = $logo ? 'C' : 'A';
        if ($logo) {
            (new Drawing())->setPath(Storage::disk('public')->path($tenant->logo_path))->setHeight(48)->setCoordinates('A1')->setOffsetX(4)->setOffsetY(4)->setWorksheet($sheet);
        }

        $sheet->setCellValueExplicit("{$text}1", mb_strtoupper((string) ($tenant?->company_name ?? '')), DataType::TYPE_STRING);
        $sheet->setCellValue("{$text}2", 'HOJA DE CONDUCCIÓN — ' . mb_strtoupper($leg));
        $sheet->setCellValue("{$text}3", 'Bitácora de viajes · horas de conducción de salida a llegada');
        $sheet->setCellValue('K1', 'PERIODO');
        $sheet->setCellValue('K2', "Del {$dmy($from)} al {$dmy($to)}");
        $sheet->setCellValue('K3', 'Emitido: ' . now(tenant_timezone())->format('d/m/Y H:i'));
        foreach ([1, 2, 3] as $r) {
            $sheet->mergeCells("{$text}{$r}:J{$r}");
            $sheet->mergeCells("K{$r}:M{$r}");
        }
        $sheet->getStyle("{$text}1")->getFont()->setBold(true)->setSize(15)->getColor()->setRGB($brand);
        $sheet->getStyle("{$text}2")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('111827');
        $sheet->getStyle("{$text}3")->getFont()->setSize(9)->getColor()->setRGB('6B7280');
        $sheet->getStyle('K1:M3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('K1')->getFont()->setBold(true)->setSize(8)->getColor()->setRGB('6B7280');
        $sheet->getStyle('K2')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('K3')->getFont()->setSize(8)->getColor()->setRGB('6B7280');
        $sheet->getStyle('A1:M3')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getStyle('A4:M4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brand);
        $sheet->getRowDimension(4)->setRowHeight(4);
        $sheet->getRowDimension(5)->setRowHeight(10);
    }

    private function tableHead(Worksheet $sheet, string $leg, string $color): void
    {
        $h = self::HEAD;
        $h2 = $h + 1;
        $col = 1;
        foreach (self::GROUPS as [$label, $span]) {
            $start = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $end = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + $span - 1);
            $sheet->setCellValue("{$start}{$h}", $label === 'TRAMO' ? mb_strtoupper($leg) : $label);
            $span === 1 ? $sheet->mergeCells("{$start}{$h}:{$start}{$h2}") : $sheet->mergeCells("{$start}{$h}:{$end}{$h}");
            $col += $span;
        }
        $sheet->fromArray(self::COLUMNS, null, "B{$h2}");

        $head = $sheet->getStyle("A{$h}:M{$h2}");
        $head->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
        $head->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $head->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::DARK);
        $sheet->getStyle("H{$h}:J{$h2}")->getFill()->getStartColor()->setRGB($color);
        $sheet->getStyle("B{$h}:M{$h}")->getFont()->setSize(11);
        $sheet->getRowDimension($h)->setRowHeight(22);
        $sheet->getRowDimension($h2)->setRowHeight(28);
    }

    private function signatures(Worksheet $sheet, int $row): void
    {
        foreach (['C' => 'E', 'J' => 'M'] as $start => $end) {
            $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
            $sheet->getStyle("{$start}{$row}:{$end}{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::DARK);
            $sheet->getStyle("{$start}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("{$start}{$row}")->getFont()->setBold(true)->setSize(9);
            $sheet->mergeCells("{$start}" . ($row + 1) . ":{$end}" . ($row + 1));
            $sheet->getStyle("{$start}" . ($row + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("{$start}" . ($row + 1))->getFont()->setSize(8)->getColor()->setRGB('6B7280');
        }
        $sheet->setCellValue("C{$row}", 'SUPERVISOR');
        $sheet->setCellValue('C' . ($row + 1), 'Nombre, firma y fecha');
        $sheet->setCellValue("J{$row}", 'V° B° RECURSOS HUMANOS');
        $sheet->setCellValue('J' . ($row + 1), 'Nombre, firma y fecha');
    }
}
