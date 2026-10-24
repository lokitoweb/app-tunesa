<?php

namespace App\Modules\Core\Support;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Lectura de importaciones Excel/CSV (caja chica, planilla…): la fila 1 es el encabezado y
 * cada columna se reconoce por palabra clave (sin tildes ni mayúsculas), así un archivo con
 * encabezados parecidos también se lee. Las filas vacías se omiten.
 */
final class SpreadsheetRows
{
    /**
     * @param  array<string, array{0: string, 1: list<string>}>  $columns  encabezado => [campo, palabras clave]
     * @return array<int, array<string, mixed>>  n.º de fila del archivo => [campo => valor]
     */
    public static function read(string $path, array $columns): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $data = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);

        $map = self::headerMap(array_shift($data) ?? [], $columns);
        $rows = [];
        foreach ($data as $i => $cells) {
            $row = [];
            foreach ($map as $index => $field) {
                $row[$field] = is_string($cells[$index] ?? null) ? trim($cells[$index]) : ($cells[$index] ?? null);
            }
            if (array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                $rows[$i + 2] = $row; // +2: la fila 1 es el encabezado
            }
        }

        return $rows;
    }

    /** Plantilla .xlsx con los encabezados en negrita y una fila de ejemplo */
    public static function template(string $path, string $title, array $columns, array $example): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle($title);
        $sheet->fromArray([array_keys($columns), $example]);
        $last = chr(ord('A') + count($columns) - 1);
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        foreach (range('A', $last) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        (new Xlsx($book))->save($path);
    }

    /** Índice de columna => campo, reconociendo el encabezado por palabra clave */
    private static function headerMap(array $header, array $columns): array
    {
        $map = [];
        foreach ($header as $index => $title) {
            $key = Str::lower(Str::ascii((string) $title));
            foreach ($columns as [$field, $keywords]) {
                if (! in_array($field, $map, true) && Str::contains($key, $keywords)) {
                    $map[$index] = $field;
                    break;
                }
            }
        }

        return $map;
    }
}
