<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba sale a internet (Nominatim, Google, FCM, webhooks):
        // cada una simula lo que necesita con Http::fake().
        Http::preventStrayRequests();

        // Las pantallas Vue se prueban por sus props (assertInertia), sin compilar assets
        $this->withoutVite();

        // En una pantalla Vue (Inertia) lo que se pinta son sus props: estos
        // helpers buscan en ese JSON legible, como assertSee / assertDontSee en Blade.
        TestResponse::macro('prop', fn (?string $key = null) => data_get($this->viewData('page')['props'], $key));
        TestResponse::macro('pageText', fn () => json_encode($this->prop(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        TestResponse::macro('assertPageSees', function (string $text) {
            Assert::assertStringContainsString($text, $this->pageText());

            return $this;
        });
        TestResponse::macro('assertPageMisses', function (string $text) {
            Assert::assertStringNotContainsString($text, $this->pageText());

            return $this;
        });
    }

    /**
     * Par RSA de prueba (cuentas de servicio FCM). Pasa su propio openssl.cnf:
     * PHP en Windows (XAMPP) busca uno que no existe y la generación falla.
     *
     * @return array{0: string, 1: string} [llave privada PEM, llave pública PEM]
     */
    protected function makeRsaKeyPair(): array
    {
        $config = ['config' => __DIR__ . '/Fixtures/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($config);
        openssl_pkey_export($key, $pem, null, $config);

        return [$pem, openssl_pkey_get_details($key)['key']];
    }
}
