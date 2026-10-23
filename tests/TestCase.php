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
     * PDF real mínimo para subir (UploadedFile::fake()->create() da un archivo vacío,
     * que SafeUpload rechaza con razón: no es un PDF).
     */
    protected function fakePdf(string $name = 'documento.pdf'): \Illuminate\Http\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            . "trailer << /Root 1 0 R >>\n%%EOF\n" . str_repeat(' ', 512));

        return new \Illuminate\Http\UploadedFile($path, $name, 'application/pdf', null, true);
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
