<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Core\Models\TenantSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Consulta de RUC (SUNAT) y DNI (RENIEC) en Migo, como el ERP de escritorio: POST {ruc|dni, token}.
 * Las credenciales son las de la empresa o de la plataforma (Integraciones). Una sola clase para las
 * dos consultas: cualquier pantalla que necesite autocompletar por documento la usa.
 */
class MigoLookup
{
    public function __construct(private readonly TenantSetting $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->migo()['token'];
    }

    /**
     * @return array{name: string, address: string, city: ?string}|null null = el documento no existe
     *
     * @throws RuntimeException si el servicio no está configurado o no responde
     */
    public function ruc(string $ruc): ?array
    {
        $data = $this->post('ruc_url', ['ruc' => $ruc]);

        return empty($data['nombre_o_razon_social']) ? null : [
            'name'    => trim($data['nombre_o_razon_social']),
            'address' => trim((string) ($data['direccion'] ?? '')),
            'city'    => $data['distrito'] ?? $data['provincia'] ?? null,
        ];
    }

    /** @return array{name: string, address: string, city: ?string}|null */
    public function dni(string $dni): ?array
    {
        $data = $this->post('dni_url', ['dni' => $dni]);

        // El .NET solo usaba la respuesta si no traía "errors"
        return ! empty($data['errors']) || empty($data['nombre']) ? null : [
            'name'    => trim($data['nombre']),
            'address' => trim((string) ($data['direccion'] ?? '')),
            'city'    => $data['distrito'] ?? $data['provincia'] ?? null,
        ];
    }

    private function post(string $urlKey, array $payload): array
    {
        ['token' => $token] = $config = $this->settings->migo();
        if (! $token) {
            throw new RuntimeException('La consulta por documento no está configurada (Integraciones › Migo).');
        }

        try {
            $response = Http::timeout(10)->acceptJson()
                ->withHeaders(['Authorization' => "Token token={$token}"])
                ->post($config[$urlKey], $payload + ['token' => $token]);
        } catch (\Throwable) {
            $response = null;
        }
        // Una caída del servicio no es "documento no encontrado"
        if (! $response || $response->serverError()) {
            throw new RuntimeException('Migo no respondió. Completa los datos a mano.');
        }

        return is_array($data = $response->json()) ? $data : [];
    }
}
