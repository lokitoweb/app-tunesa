<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Textos de los documentos del cese, con campos {así} que se llenan solos. Cada empresa edita los
 * suyos; sin fila rige el texto estándar (las mejoras del estándar llegan solas a quien no lo cambió).
 */
class DocumentTemplate extends Model
{
    use BelongsToTenant;

    /** Campos disponibles en los textos */
    public const FIELDS = [
        'empresa' => 'Razón social', 'ruc' => 'RUC', 'direccion' => 'Dirección de la empresa',
        'representante' => 'Quien firma por la empresa', 'representante_cargo' => 'Su cargo', 'representante_dni' => 'Su DNI',
        'colaborador' => 'Nombre del colaborador', 'documento' => 'Su documento (tipo y número)', 'cargo' => 'Su cargo',
        'fecha_ingreso' => 'Fecha de ingreso', 'fecha_cese' => 'Fecha de cese', 'motivo' => 'Motivo de cese',
        'banco_cts' => 'Banco de la CTS', 'cuenta_cts' => 'Cuenta CTS', 'fecha_documento' => 'Fecha del documento',
        'periodo_cts' => 'Semestre de la CTS (depósito)', 'fecha_deposito' => 'Fecha límite del depósito', 'monto_cts' => 'Monto depositado',
    ];

    /** key => [título, texto estándar] */
    public const DEFAULTS = [
        'settlement_intro' => ['Liquidación: párrafo inicial',
            '{empresa}, con RUC N.° {ruc}, con domicilio en {direccion}, representada por {representante} ({representante_cargo}), da cumplimiento a las obligaciones laborales que asumió como empleador con {colaborador}, identificado(a) con {documento}.'],
        'settlement_closing' => ['Liquidación: texto de conformidad',
            "Se expide el presente documento como constancia del cálculo, determinación y pago de los beneficios sociales. Las partes lo suscriben y declaran su conformidad con el periodo de servicios reconocido, el cálculo de las remuneraciones y beneficios sociales y el monto pagado.\n\nSuscrito el {fecha_cese}."],
        'work_certificate' => ['CERTIFICADO DE TRABAJO',
            "El que suscribe, en representación de {empresa}, con RUC N.° {ruc},\n\nCERTIFICA:\n\nQue {colaborador}, identificado(a) con {documento}, ha laborado en nuestra empresa desde el {fecha_ingreso} hasta el {fecha_cese}, ocupando el cargo de «{cargo}».\n\nSe expide el presente certificado a solicitud del interesado para los fines que estime conveniente.\n\n{fecha_documento}."],
        'cts_release_letter' => ['Carta de liberación de CTS',
            "{fecha_documento}\n\nSeñores\n{banco_cts}\nPresente.-\n\nDe nuestra consideración:\n\n{empresa}, con RUC N.° {ruc}, con domicilio en {direccion}, debidamente representada por {representante}, identificado(a) con DNI {representante_dni}, se dirige a ustedes para comunicarles que {colaborador}, identificado(a) con {documento}, laboró en nuestra empresa hasta el {fecha_cese}.\n\nPor lo tanto, extendemos la presente para que se sirvan hacer efectivo el total de los depósitos de la Compensación por Tiempo de Servicios más los intereses correspondientes, realizados en su cuenta N.° {cuenta_cts}, conforme al D. Leg. N.° 650.\n\nSin otro particular, quedamos de ustedes.\n\nAtentamente,"],
        'cts_deposit_certificate' => ['LIQUIDACIÓN DE DEPÓSITO DE CTS',
            '{empresa}, con RUC N.° {ruc}, con domicilio en {direccion}, representada por {representante} ({representante_cargo}), en aplicación del artículo 24 del TUO del D. Leg. N.° 650, Ley de Compensación por Tiempo de Servicios, otorga a {colaborador}, identificado(a) con {documento}, la constancia del depósito de su CTS del periodo {periodo_cts}, a más tardar el {fecha_deposito}, en su cuenta N.° {cuenta_cts} de {banco_cts}, por {monto_cts}, según el siguiente cálculo:'],
    ];

    /** Las que imprimen su título como encabezado (en las demás es solo el nombre de la plantilla) */
    public const TITLED = ['work_certificate', 'cts_deposit_certificate'];

    protected $fillable = ['tenant_id', 'key', 'title', 'body', 'updated_by'];

    /** "30 de setiembre de 2025" (setiembre, como se escribe en Perú) */
    public static function longDate(\DateTimeInterface $date): string
    {
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];

        return (int) $date->format('j') . ' de ' . $months[(int) $date->format('n') - 1] . ' de ' . $date->format('Y');
    }

    /** @return array{title: string, body: string, custom: bool} */
    public static function resolve(int $tenantId, string $key): array
    {
        $row = static::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->first();
        [$title, $body] = self::DEFAULTS[$key];

        return ['title' => $row->title ?? $title, 'body' => $row->body ?? $body, 'custom' => (bool) $row];
    }

    /**
     * Título (texto plano) y cuerpo (HTML escapado: párrafos por línea en blanco, saltos de línea
     * dentro de cada uno) con los campos llenos. Un campo sin valor queda como "—".
     */
    public static function render(int $tenantId, string $key, array $vars): array
    {
        $t = static::resolve($tenantId, $key);
        $fill = fn (string $text) => preg_replace_callback('/\{(\w+)\}/',
            fn ($m) => array_key_exists($m[1], self::FIELDS) ? ((string) ($vars[$m[1]] ?? '') ?: '—') : $m[0], $text);
        $paragraphs = array_map(fn ($p) => '<p>' . nl2br(e($fill($p))) . '</p>', preg_split('/\n\s*\n/', trim($t['body'])));

        return ['title' => $fill($t['title']), 'html' => new HtmlString(implode("\n", $paragraphs))];
    }
}
