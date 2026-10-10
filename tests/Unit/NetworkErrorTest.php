<?php

namespace Tests\Unit;

use App\Support\NetworkError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Los errores de red (cURL/Guzzle) se muestran en español y sin detalles técnicos */
class NetworkErrorTest extends TestCase
{
    public function test_curl_messages_are_translated(): void
    {
        $cases = [
            'cURL error 28: Operation timed out after 10001 milliseconds' => 'Sin respuesta dentro del tiempo límite.',
            'cURL error 6: Could not resolve host: erp.invalid'           => 'No se encontró el servidor (revisa el dominio de la URL).',
            'cURL error 7: Failed to connect: Connection refused'         => 'El servidor rechazó la conexión.',
            'cURL error 56: CONNECT tunnel failed, response 403'          => 'La red del servidor bloqueó la conexión (proxy o firewall). Pide a soporte que habilite la salida a ese dominio.',
            'cURL error 60: SSL certificate problem: self-signed'         => 'No se pudo verificar el certificado de seguridad (HTTPS) del destino. Si el certificado es válido, actualiza los certificados raíz del servidor (curl.cainfo en php.ini).',
            'cURL error 35: unknown'                                       => 'No se pudo conectar con el servidor de destino.',
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, NetworkError::describe(new RuntimeException($raw)), $raw);
            $this->assertSame($expected, NetworkError::humanize($raw), $raw);
        }
    }

    public function test_readable_messages_are_kept(): void
    {
        $this->assertSame('El destino respondió HTTP 500.', NetworkError::humanize('El destino respondió HTTP 500.'));
        $this->assertNull(NetworkError::humanize(null));
    }
}
