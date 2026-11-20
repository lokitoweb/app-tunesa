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
 * Hoja de control de viajes en Excel con el formato del requerimiento de Tunesa
 * («Formato de asistencia de choferes»): IDA (fecha, ruta, chofer / ayudante, placa, hora salida),
 * VUELTA (fecha, hora, ruta) y FIRMA en blanco, para imprimir y que el chofer firme.
 * Cabecera con el logo y el color de la empresa (marca blanca) y firmas del supervisor y RR. HH. al pie.
 */
class TripSheetExporter
{
    public const IDA    = ['FECHA', 'RUTA', 'CHOFER / AYUDANTE', 'PLACA', 'HORA SALIDA'];
    public const VUELTA = ['FECHA', 'HORA', 'RUTA'];

    /** Filas mínimas de la tabla: las que sobran quedan en blanco para llenarlas a mano */
    private const MIN_ROWS = 15;

    /** Primera fila del encabezado de la tabla (N° | IDA | VUELTA | FIRMA) */
    public const HEAD = 6;

    private const VUELTA_DARK  = 'B45309';
    private const VUELTA_LIGHT = 'FEF3C7';
    private const VUELTA_ROW   = 'FFFBEB';
    private const FIRMA_DARK   = '374151';
    private const GRID         = 'BFC5CD';

    /** @param Collection<int, TripLog> $trips */
    public function xlsx(Collection $trips, ?Tenant $tenant, string $from, string $to, string $path): void
    {
        $book  = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $book->getProperties()->setTitle('Formato de asistencia de choferes')->setCreator(config('app.name'));
        $sheet = $book->getActiveSheet()->setTitle('Bitácora de viajes')->setShowGridlines(false);

        $brand = ltrim((string) ($tenant?->primary_color ?: '#3C729E'), '#');
        $h     = self::HEAD;
        $first = $h + 2;

        $this->title($sheet, $tenant, $brand, $from, $to);
        $this->tableHead($sheet, $brand);

        $dmy = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';
        $hm  = fn ($t) => $t ? substr((string) $t, 0, 5) : '';
        $nm  = fn (?User $u) => $u ? "{$u->last_name}, {$u->first_name}" : '';

        $row = $first;
        foreach ($trips->values() as $n => $trip) {
            $values = [
                $n + 1,
                $dmy($trip->trip_date), $trip->route,
                $nm($trip->driver) . ($trip->helper ? "\nAyud.: " . $nm($trip->helper) : ''),
                $trip->vehicle_plate, $hm($trip->departure_time),
                $dmy($trip->return_date), $hm($trip->return_time), $trip->return_route,
            ];
            foreach ($values as $i => $value) {
                $sheet->setCellValueExplicit([$i + 1, $row], (string) $value, $i === 0 ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            $row++;
        }
        $last = max($row - 1, $first + self::MIN_ROWS - 1);
        for ($r = $row; $r <= $last; $r++) {
            $sheet->setCellValue("A{$r}", $r - $first + 1);
        }

        // Cuerpo: líneas finas, VUELTA con su tono, firma con espacio para firmar a mano
        $body = $sheet->getStyle("A{$first}:J{$last}");
        $body->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::GRID);
        $body->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        foreach (['A', 'B', 'E', 'F', 'G', 'H'] as $col) {
            $sheet->getStyle("{$col}{$first}:{$col}{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        foreach (['C', 'D', 'I'] as $col) {
            $sheet->getStyle("{$col}{$first}:{$col}{$last}")->getAlignment()->setIndent(1);
        }
        $sheet->getStyle("A{$first}:A{$last}")->getFont()->setSize(9)->getColor()->setRGB('6B7280');
        $this->fill($sheet, "G{$first}:I{$last}", self::VUELTA_ROW);
        for ($r = $first; $r <= $last; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(36);
        }
        // Contorno de la tabla y separación marcada entre IDA, VUELTA y FIRMA
        $sheet->getStyle("A{$h}:J{$last}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::FIRMA_DARK);
        foreach (['G', 'J'] as $col) {
            $sheet->getStyle("{$col}{$h}:{$col}{$last}")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::FIRMA_DARK);
        }

        $this->signatures($sheet, $last + 3);

        foreach (['A' => 5, 'B' => 11, 'C' => 24, 'D' => 30, 'E' => 10, 'F' => 9, 'G' => 11, 'H' => 8, 'I' => 24, 'J' => 24] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // Impresión: A4 horizontal, una página de ancho, encabezado de la tabla repetido en cada hoja
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setHorizontalCentered(true)
            ->setRowsToRepeatAtTopByStartAndEnd($h, $h + 1)->setPrintArea('A1:J' . ($last + 6));
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
        $sheet->getHeaderFooter()->setOddFooter('&L&8' . $this->footerText($tenant) . '&R&8Página &P de &N');
        $sheet->freezePane('A' . $first);

        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    /** Cabecera: logo, empresa, título del formato y periodo, sobre una franja del color de la empresa */
    private function title(Worksheet $sheet, ?Tenant $tenant, string $brand, string $from, string $to): void
    {
        $dmy = fn ($d) => Carbon::parse($d)->format('d/m/Y');

        $logo = $tenant?->logo_path && Storage::disk('public')->exists($tenant->logo_path)
            && in_array(strtolower(pathinfo($tenant->logo_path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true);
        $text = $logo ? 'C' : 'A';
        if ($logo) {
            $drawing = new Drawing();
            $drawing->setPath(Storage::disk('public')->path($tenant->logo_path))->setHeight(48)->setCoordinates('A1')->setOffsetX(4)->setOffsetY(4)->setWorksheet($sheet);
        }

        $sheet->setCellValueExplicit("{$text}1", mb_strtoupper((string) ($tenant?->company_name ?? '')), DataType::TYPE_STRING);
        $sheet->setCellValue("{$text}2", 'FORMATO DE ASISTENCIA DE CHOFERES');
        $sheet->setCellValue("{$text}3", 'Control de viajes · Ida y vuelta');
        $sheet->setCellValue('I1', 'PERIODO');
        $sheet->setCellValue('I2', "Del {$dmy($from)} al {$dmy($to)}");
        $sheet->setCellValue('I3', 'Emitido: ' . now(tenant_timezone())->format('d/m/Y H:i'));
        foreach ([1, 2, 3] as $r) {
            $sheet->mergeCells("{$text}{$r}:H{$r}");
            $sheet->mergeCells("I{$r}:J{$r}");
        }

        $sheet->getStyle("{$text}1")->getFont()->setBold(true)->setSize(15)->getColor()->setRGB($brand);
        $sheet->getStyle("{$text}2")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('111827');
        $sheet->getStyle("{$text}3")->getFont()->setSize(9)->getColor()->setRGB('6B7280');
        $sheet->getStyle('I1:J3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('I1')->getFont()->setBold(true)->setSize(8)->getColor()->setRGB('6B7280');
        $sheet->getStyle('I2')->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('111827');
        $sheet->getStyle('I3')->getFont()->setSize(8)->getColor()->setRGB('6B7280');
        $sheet->getStyle('A1:J3')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(16);

        // Franja del color de la empresa bajo la cabecera
        $this->fill($sheet, 'A4:J4', $brand);
        $sheet->getRowDimension(4)->setRowHeight(4);
        $sheet->getRowDimension(5)->setRowHeight(10);
    }

    /** Encabezado de la tabla en dos filas: N° | IDA | VUELTA | FIRMA, con N° y FIRMA ocupando ambas */
    private function tableHead(Worksheet $sheet, string $brand): void
    {
        $h  = self::HEAD;
        $h2 = $h + 1;

        $sheet->setCellValue("A{$h}", 'N°')->mergeCells("A{$h}:A{$h2}");
        $sheet->setCellValue("B{$h}", 'IDA')->mergeCells("B{$h}:F{$h}");
        $sheet->setCellValue("G{$h}", 'VUELTA')->mergeCells("G{$h}:I{$h}");
        $sheet->setCellValue("J{$h}", 'FIRMA DEL CHOFER')->mergeCells("J{$h}:J{$h2}");
        $sheet->fromArray([...self::IDA, ...self::VUELTA], null, "B{$h2}");

        $head = $sheet->getStyle("A{$h}:J{$h2}");
        $head->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
        $head->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $head->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('FFFFFF');

        $this->fill($sheet, "A{$h}:A{$h2}", self::FIRMA_DARK);
        $this->fill($sheet, "J{$h}:J{$h2}", self::FIRMA_DARK);
        $this->fill($sheet, "B{$h}:F{$h}", $brand);
        $this->fill($sheet, "G{$h}:I{$h}", self::VUELTA_DARK);
        $sheet->getStyle("B{$h}:I{$h}")->getFont()->setSize(11);

        // Segunda fila en tonos claros del mismo color, texto oscuro
        $this->fill($sheet, "B{$h2}:F{$h2}", $this->tint($brand));
        $this->fill($sheet, "G{$h2}:I{$h2}", self::VUELTA_LIGHT);
        $sheet->getStyle("B{$h2}:F{$h2}")->getFont()->getColor()->setRGB($brand);
        $sheet->getStyle("G{$h2}:I{$h2}")->getFont()->getColor()->setRGB(self::VUELTA_DARK);

        $sheet->getRowDimension($h)->setRowHeight(22);
        $sheet->getRowDimension($h2)->setRowHeight(20);
    }

    /** Firmas al pie: supervisor que registra y visto bueno de RR. HH. */
    private function signatures(Worksheet $sheet, int $row): void
    {
        foreach (['C' => 'D', 'I' => 'J'] as $start => $end) {
            $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
            $sheet->getStyle("{$start}{$row}:{$end}{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::FIRMA_DARK);
            $sheet->getStyle("{$start}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("{$start}{$row}")->getFont()->setBold(true)->setSize(9);
            $sheet->mergeCells("{$start}" . ($row + 1) . ":{$end}" . ($row + 1));
            $sheet->getStyle("{$start}" . ($row + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("{$start}" . ($row + 1))->getFont()->setSize(8)->getColor()->setRGB('6B7280');
        }
        $sheet->setCellValue("C{$row}", 'SUPERVISOR');
        $sheet->setCellValue('C' . ($row + 1), 'Nombre, firma y fecha');
        $sheet->setCellValue("I{$row}", 'V° B° RECURSOS HUMANOS');
        $sheet->setCellValue('I' . ($row + 1), 'Nombre, firma y fecha');
    }

    private function footerText(?Tenant $tenant): string
    {
        return str_replace('&', '&&', (string) ($tenant?->company_name ?? '')) . ' · Formato de asistencia de choferes';
    }

    private function fill(Worksheet $sheet, string $range, string $rgb): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    /** Tono claro (88 % hacia blanco) del color de la empresa para la segunda fila del encabezado */
    private function tint(string $rgb): string
    {
        return collect(str_split($rgb, 2))->map(fn ($c) => sprintf('%02X', (int) round(hexdec($c) + (255 - hexdec($c)) * 0.88)))->implode('');
    }
}
