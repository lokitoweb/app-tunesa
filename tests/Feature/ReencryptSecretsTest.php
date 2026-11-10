<?php

namespace Tests\Feature;

use App\Modules\Core\Console\ReencryptSecrets;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Rotación de APP_KEY (SECURITY_AUDIT.md §2): con la clave anterior en APP_PREVIOUS_KEYS todo sigue legible,
 * app:reencrypt pasa los datos a la clave nueva y, al quitar la anterior, no se pierde nada.
 */
class ReencryptSecretsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function useKeys(string $key, array $previous = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private static function newKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function test_rotation_with_previous_key_and_reencrypt_keeps_every_value(): void
    {
        [$old, $new] = [self::newKey(), self::newKey()];
        $this->useKeys($old);

        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $emp = $this->makeUser($acme, 'employee');
        EmployeeProfile::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'bank_account_number' => '19112345678001', 'pension_code' => 'CUSPP123']);
        $row = TenantSetting::editableRowFor($acme);
        $row->smtp_password = 'clave-smtp-prueba';
        $row->save();
        $rawBefore = DB::table('employee_profiles')->value('bank_account_number');

        // 1. Clave nueva + anterior en APP_PREVIOUS_KEYS: se sigue leyendo todo
        $this->useKeys($new, [$old]);
        $this->assertSame('19112345678001', EmployeeProfile::first()->bank_account_number);

        // 2. Vista previa: no cambia nada
        $this->artisan('app:reencrypt', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($rawBefore, DB::table('employee_profiles')->value('bank_account_number'));

        // 3. Re-cifrado con la clave nueva
        $this->artisan('app:reencrypt')->assertSuccessful();
        $this->assertNotSame($rawBefore, DB::table('employee_profiles')->value('bank_account_number'));

        // 4. Sin la clave anterior: todo legible, nada perdido
        $this->useKeys($new);
        $profile = EmployeeProfile::first();
        $this->assertSame(['19112345678001', 'CUSPP123'], [$profile->bank_account_number, $profile->pension_code]);
        $this->assertSame('clave-smtp-prueba', TenantSetting::editableRowFor($acme->fresh())->smtp_password);
    }

    public function test_values_it_cannot_decrypt_are_left_untouched_and_it_fails(): void
    {
        $this->useKeys(self::newKey());
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $emp = $this->makeUser($acme, 'employee');
        EmployeeProfile::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'bank_account_number' => '19112345678001']);
        $raw = DB::table('employee_profiles')->value('bank_account_number');

        // Clave nueva sin la anterior: no se puede descifrar → no se toca y se avisa
        $this->useKeys(self::newKey());
        $this->artisan('app:reencrypt')->assertFailed();
        $this->assertSame($raw, DB::table('employee_profiles')->value('bank_account_number'));
    }

    public function test_every_model_with_encrypted_columns_is_covered(): void
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() === 'php' && preg_match("/=>\\s*'encrypted/", file_get_contents($file->getPathname()))
                && preg_match('/namespace ([^;]+);.*?class (\w+)/s', file_get_contents($file->getPathname()), $m)
                && is_subclass_of($m[1] . '\\' . $m[2], \Illuminate\Database\Eloquent\Model::class)) {
                $found[] = $m[1] . '\\' . $m[2];
            }
        }
        sort($found);
        $covered = ReencryptSecrets::MODELS;
        sort($covered);
        $this->assertSame($covered, $found, 'Un modelo nuevo con columnas encrypted debe agregarse a ReencryptSecrets::MODELS');
    }
}
