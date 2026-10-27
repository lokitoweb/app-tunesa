<?php

namespace Tests\Feature;

use App\Modules\Integrations\Services\WebhookSender;
use Tests\TestCase;

/** Auditoría de seguridad: el filtro de webhooks cubre IPv6 y, en producción, dominios que no resuelven */
class WebhookSsrfTest extends TestCase
{
    public function test_private_and_local_ipv6_addresses_are_blocked(): void
    {
        $sender = app(WebhookSender::class);
        // ::ffff:127.0.0.1 y ::ffff:10.0.0.5 envuelven una IPv4 privada: PHP solo no las detecta
        foreach (['http://[::1]/hook', 'https://[fd00::5]/hook', 'http://[fe80::1]/hook', 'http://[::ffff:127.0.0.1]/hook',
            'http://[::ffff:10.0.0.5]/hook', 'http://[::ffff:169.254.169.254]/hook', 'http://localhost:8080/x'] as $url) {
            $this->assertNotNull($sender->blockedReason($url), $url);
        }
        $this->assertNull($sender->blockedReason('https://[2606:4700:4700::1111]/hook'), 'IPv6 pública');
    }

    public function test_an_unresolvable_domain_is_blocked_only_in_production(): void
    {
        $sender = app(WebhookSender::class);
        $this->assertNull($sender->blockedReason('https://no-existe.example.test/hook'), 'fuera de producción (pruebas sin red)');

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame('No se pudo resolver el dominio de la URL. Revisa que esté bien escrito.', $sender->blockedReason('https://no-existe.example.test/hook'));
    }
}
