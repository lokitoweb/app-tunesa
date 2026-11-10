<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Política de privacidad administrable (Configuración › Marca y app › Políticas de la app).
 *
 * Texto en Markdown sencillo: "## Título", "- viñeta", **negrita**, [enlace](https://…) y los
 * marcadores {app} (nombre comercial) y {eliminar_cuenta} (página para pedir eliminar la cuenta).
 * Se usa el de la empresa, si no el de la plataforma, si no el predeterminado
 * (resources/legal/privacy.md). La web lo muestra como HTML (sin HTML del autor: se descarta) y la
 * app como bloques simples que dibuja con sus propios estilos.
 */
final class PrivacyPolicy
{
    public const DEFAULT_FILE = 'legal/privacy.md';

    /** Fecha de la versión predeterminada (si nadie editó el texto) */
    public const DEFAULT_UPDATED_ON = '2026-10-12';

    public function __construct(private readonly TenantSetting $settings) {}

    public static function defaultText(): string
    {
        return trim((string) file_get_contents(resource_path(self::DEFAULT_FILE)));
    }

    /** Texto vigente sin reemplazar los marcadores (el que se edita en el panel) */
    public function source(): string
    {
        return filled($this->settings->privacy_policy) ? trim((string) $this->settings->privacy_policy) : self::defaultText();
    }

    public function markdown(): string
    {
        return strtr($this->source(), [
            '{app}'             => $this->settings->tradeName(),
            '{eliminar_cuenta}' => route('legal.account-deletion'),
        ]);
    }

    public function html(): string
    {
        return Str::markdown($this->markdown(), ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function updatedOn(): Carbon
    {
        return Carbon::parse($this->settings->privacy_policy_updated_on ?: self::DEFAULT_UPDATED_ON);
    }

    /**
     * Bloques para la app: heading, paragraph y bullet. El texto conserva **negrita**; los enlaces
     * quedan como "texto (url)".
     *
     * @return list<array{type: string, text: string}>
     */
    public function blocks(): array
    {
        $blocks = [];
        $paragraph = [];
        $flush = function () use (&$blocks, &$paragraph) {
            if ($paragraph) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $paragraph)];
                $paragraph = [];
            }
        };
        $links = fn (string $text) => preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '$1 ($2)', $text);

        foreach (preg_split('/\R/', $this->markdown()) as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
            } elseif (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
                $flush();
                $blocks[] = ['type' => 'heading', 'text' => $links($m[1])];
            } elseif (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                $flush();
                $blocks[] = ['type' => 'bullet', 'text' => $links($m[1])];
            } else {
                $paragraph[] = $links($line);
            }
        }
        $flush();

        return $blocks;
    }
}
