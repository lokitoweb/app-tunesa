<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Límite "api" (60/min): por usuario, no por IP (toda una sede sale por la misma IP) */
class ApiRateLimitTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_one_user_exhausting_the_limit_does_not_block_others_on_the_same_ip(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $luis = $this->makeUser($acme);

        $anaToken  = $ana->createToken('t')->plainTextToken;
        $luisToken = $luis->createToken('t')->plainTextToken;
        $get = function (string $token) use ($acme) {
            $this->app['auth']->forgetGuards();

            return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
                ->withHeaders(['Authorization' => "Bearer {$token}", 'X-Tenant-Slug' => $acme->slug])
                ->getJson('/api/auth/me');
        };

        for ($i = 0; $i < 60; $i++) {
            $get($anaToken)->assertOk();
        }
        $get($anaToken)->assertStatus(429);

        // Misma IP, otra persona: no comparte el límite de Ana
        $get($luisToken)->assertOk();
    }
}
