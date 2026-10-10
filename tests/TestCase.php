<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba sale a internet (Nominatim, Google, FCM, webhooks):
        // cada una simula lo que necesita con Http::fake().
        Http::preventStrayRequests();
    }
}
