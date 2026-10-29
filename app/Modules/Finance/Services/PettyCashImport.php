<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Support\SpreadsheetRows;
use App\Modules\Finance\Models\Supplier;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importación de caja chica desde Excel/CSV con las columnas del ERP de escritorio.
 * Solo normaliza las filas (la lectura es SpreadsheetRows); la validación y el guardado
 * son los mismos del registro manual (PettyCashWebController).
 */
final class PettyCashImport
{
    public const MAX_ROWS = 1000;

    /** Encabezado de la plantilla => campo (se reconoce por palabra clave, sin tildes) */
    public const COLUMNS = [
        'Fecha'                     => ['date', ['fecha']],
        'Tipo de Documento'         => ['document_type', ['tipo']],
        'RUC/Proveedor'             => ['supplier', ['ruc', 'proveedor']],
        'Descripción del gasto'     => ['description', ['descrip', 'concepto', 'glosa']],
        'Categoría/Centro de Costo' => ['category', ['categ', 'centro', 'clasif']],
        'Moneda'                    => ['currency', ['moneda']],
        'Monto Total'               => ['amount', ['monto', 'importe']],
        'Número de Comprobante'     => ['document_number', ['comprobante', 'numero', 'nro']],
    ];

    /**
     * Columnas que solo trae la plantilla del ERP de escritorio (C:\ERP negreiros\CAJA_CHICA.xlsx: FECHA, MEDIO PAGO,
     * CLASIFICACION, SERIE, NRO, RUC, GLOSA, RESPONSABLE, NRO RECIBO, MONTO): la serie va delante del número.
     */
    private const LEGACY_COLUMNS = ['Serie' => ['series', ['serie']]];

    /** Plantilla vacía con los encabezados y una fila de ejemplo */
    public static function template(string $path): void
    {
        SpreadsheetRows::template($path, 'Caja chica', self::COLUMNS,
            [now()->format('d/m/Y'), 'BOLETA', '20123456789', 'Útiles de oficina', 'ADMINISTRACIÓN', 'PEN', 45.50, 'B001-000123']);
    }

    /**
     * Filas del archivo como datos del formulario manual: [n.º de fila => datos].
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(string $path): array
    {
        $rows = SpreadsheetRows::read($path, self::COLUMNS + self::LEGACY_COLUMNS);

        // Razón social del proveedor ya registrado (una sola consulta)
        $rucs = array_filter(array_column($rows, 'supplier'), fn ($v) => preg_match('/^\d{11}$/', (string) $v));
        $names = $rucs ? Supplier::whereIn('ruc', array_unique($rucs))->pluck('name', 'ruc') : collect();

        return array_map(fn (array $row) => self::normalize($row, $names), $rows);
    }

    private static function normalize(array $row, $supplierNames): array
    {
        $supplier = (string) ($row['supplier'] ?? '');
        $isRuc = (bool) preg_match('/^\d{11}$/', $supplier);
        $amount = $row['amount'] ?? null;
        $series = trim((string) ($row['series'] ?? ''));
        $number = trim((string) ($row['document_number'] ?? ''));
        $number = $series !== '' && $number !== '' ? "{$series}-{$number}" : $number;
        // Sin tipo (plantilla del ERP): se deduce de la serie F001 / B001 / E001; sin comprobante, "SIN COMPROBANTE"
        $type = Str::upper(Str::ascii((string) ($row['document_type'] ?? '')));
        if ($type === '') {
            $type = match (Str::upper(substr($series ?: $number, 0, 1))) {
                'F' => 'FACTURA', 'B' => 'BOLETA', 'E' => 'RECIBO',
                default => $number !== '' ? 'TICKET' : 'SIN COMPROBANTE',
            };
        }

        return [
            'date'            => self::date($row['date'] ?? null),
            'document_type'   => $type,
            'ruc'             => $isRuc ? $supplier : null,
            'beneficiary'     => $isRuc ? ($supplierNames[$supplier] ?? null) : ($supplier !== '' ? Str::limit($supplier, 150, '') : null),
            'description'     => $row['description'] ?? null,
            'category'        => ($row['category'] ?? '') !== '' ? Str::upper((string) $row['category']) : null,
            'currency'        => self::currency($row['currency'] ?? null),
            'amount'          => self::amount($amount),
            'document_number' => $number !== '' ? $number : null,
        ];
    }

    /** Monto de Excel o texto "S/ 1,200.00" */
    public static function amount(mixed $value): mixed
    {
        return is_string($value) ? str_replace([',', 'S/', ' '], ['', '', ''], $value) : $value;
    }

    /** Fecha de Excel (número de serie) o texto d/m/Y, Y-m-d */
    public static function date(mixed $value): ?string
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
