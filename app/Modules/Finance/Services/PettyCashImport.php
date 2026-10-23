<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Supplier;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Importación de caja chica desde Excel/CSV con las columnas del ERP de escritorio.
 * Solo lee y normaliza las filas; la validación y el guardado son los mismos del
 * registro manual (PettyCashWebController).
 */
final class PettyCashImport
{
    public const MAX_ROWS = 1000;

    /** Encabezado de la plantilla => campo (se reconoce por palabra clave, sin tildes) */
    public const COLUMNS = [
        'Fecha'                     => ['date', ['fecha']],
        'Tipo de Documento'         => ['document_type', ['tipo']],
        'RUC/Proveedor'             => ['supplier', ['ruc', 'proveedor']],
        'Descripción del gasto'     => ['description', ['descrip', 'concepto']],
        'Categoría/Centro de Costo' => ['category', ['categ', 'centro']],
        'Moneda'                    => ['currency', ['moneda']],
        'Monto Total'               => ['amount', ['monto', 'importe']],
        'Número de Comprobante'     => ['document_number', ['comprobante', 'numero']],
    ];

    /** Plantilla vacía con los encabezados y una fila de ejemplo */
    public static function template(string $path): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Caja chica');
        $sheet->fromArray([array_keys(self::COLUMNS), [now()->format('d/m/Y'), 'BOLETA', '20123456789', 'Útiles de oficina', 'ADMINISTRACIÓN', 'PEN', 45.50, 'B001-000123']]);
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        (new Xlsx($book))->save($path);
    }

    /**
     * Filas del archivo como datos del formulario manual: [n.º de fila => datos].
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $data = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);

        $map = self::headerMap(array_shift($data) ?? []);
        $rucs = [];
        $rows = [];
        foreach ($data as $i => $cells) {
            $row = [];
            foreach ($map as $index => $field) {
                $row[$field] = is_string($cells[$index] ?? null) ? trim($cells[$index]) : ($cells[$index] ?? null);
            }
            if (! array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                continue; // fila vacía
            }
            $rows[$i + 2] = $row; // +2: la fila 1 es el encabezado
            if (preg_match('/^\d{11}$/', (string) ($row['supplier'] ?? ''))) {
                $rucs[] = $row['supplier'];
            }
        }

        // Razón social del proveedor ya registrado (una sola consulta)
        $names = $rucs ? Supplier::whereIn('ruc', array_unique($rucs))->pluck('name', 'ruc') : collect();

        return array_map(fn (array $row) => self::normalize($row, $names), $rows);
    }

    /** Índice de columna => campo, reconociendo el encabezado por palabra clave */
    private static function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $title) {
            $key = Str::lower(Str::ascii((string) $title));
            foreach (self::COLUMNS as [$field, $keywords]) {
                if (! in_array($field, $map, true) && Str::contains($key, $keywords)) {
                    $map[$index] = $field;
                    break;
                }
            }
        }

        return $map;
    }

    private static function normalize(array $row, $supplierNames): array
    {
        $supplier = (string) ($row['supplier'] ?? '');
        $isRuc = (bool) preg_match('/^\d{11}$/', $supplier);
        $amount = $row['amount'] ?? null;

        return [
            'date'            => self::date($row['date'] ?? null),
            'document_type'   => Str::upper(Str::ascii((string) ($row['document_type'] ?? ''))),
            'ruc'             => $isRuc ? $supplier : null,
            'beneficiary'     => $isRuc ? ($supplierNames[$supplier] ?? null) : ($supplier !== '' ? Str::limit($supplier, 150, '') : null),
            'description'     => $row['description'] ?? null,
            'category'        => ($row['category'] ?? '') !== '' ? Str::upper((string) $row['category']) : null,
            'currency'        => self::currency($row['currency'] ?? null),
            'amount'          => is_string($amount) ? str_replace([',', 'S/', ' '], ['', '', ''], $amount) : $amount,
            'document_number' => ($row['document_number'] ?? '') !== '' ? (string) $row['document_number'] : null,
        ];
    }

    /** Fecha de Excel (número de serie) o texto d/m/Y, Y-m-d */
    private static function date(mixed $value): ?string
    {
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
            $date = \DateTime::createFromFormat("!{$format}", (string) $value);
            if ($date && $date->format($format) === (string) $value) {
                return $date->format('Y-m-d');
            }
        }

        return $value === null || $value === '' ? null : (string) $value; // la validación la rechaza
    }

    /** Caja chica es en soles: vacío, S/, SOLES o PEN */
    private static function currency(mixed $value): ?string
    {
        $v = Str::upper(trim((string) $value));

        return in_array($v, ['', 'PEN', 'S/', 'S/.', 'SOLES', 'SOL'], true) ? 'PEN' : $v;
    }
}
