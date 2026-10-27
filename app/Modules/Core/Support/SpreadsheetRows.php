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
        self::export($path, $title, array_keys($columns), [$example]);
    }

    /**
     * Exporta una grilla a .xlsx (sisgere FrmReqProg: "Exportar a Excel"). Los textos que empiezan
     * con = + - @ se guardan como texto, no como fórmula (inyección de fórmulas en Excel).
     *
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     */
    public static function export(string $path, string $title, array $headers, iterable $rows): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle(Str::limit(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title), 31, ''));
        $sheet->fromArray($headers);
        $line = 2;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $value) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $line;
                is_string($value)
                    ? $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING)
                    : $sheet->setCellValue($cell, $value);
            }
            $line++;
        }
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        for ($i = 1; $i <= count($headers); $i++) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
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
