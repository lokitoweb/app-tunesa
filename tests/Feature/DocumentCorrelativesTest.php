<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Tenant;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\Requisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Correlativos RQ/OC-[EMPRESA]-[SERIE]-[N]-[AÑO] y la abreviatura única de la empresa */
class DocumentCorrelativesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_company_code_is_generated_unique_from_the_name(): void
    {
        $this->assertSame('TN', $this->makeTenant('turismo', ['company_name' => 'Turismo Negreiros'])->code);
        $this->assertSame('TR', $this->makeTenant('transporte', ['company_name' => 'Transporte Negreiros'])->code);
        $this->assertSame('TC', $this->makeTenant('costa', ['company_name' => 'Transportes de la Costa S.A.C.'])->code);
        $this->assertSame('TA', $this->makeTenant('tn3', ['company_name' => 'Transportes Negreiros'])->code);
        $this->assertSame('NO', $this->makeTenant('nono', ['company_name' => 'Ñoño'])->code);
    }

    public function test_series_is_the_branch_and_numbers_restart_each_year_per_company(): void
    {
        $acme = $this->makeTenant('acme', ['company_name' => 'Turismo Negreiros']);
        $other = $this->makeTenant('other', ['company_name' => 'Otra Empresa']);

        $this->assertSame([1, 'RQ-TN-SED-01-000001-2026'], DocumentSequence::next($acme, 'RQ', DocumentSequence::seriesFrom('sed-01'), 2026));
        $this->assertSame([2, 'RQ-TN-SED-01-000002-2026'], DocumentSequence::next($acme, 'RQ', 'SED-01', 2026));
        $this->assertSame([1, 'RQ-TN-001-000001-2026'], DocumentSequence::next($acme, 'RQ', DocumentSequence::seriesFrom(null), 2026));
        $this->assertSame([1, 'RQ-TN-SED-01-000001-2027'], DocumentSequence::next($acme, 'RQ', 'SED-01', 2027));
        $this->assertSame([1, 'OC-TN-SED-01-000001-2026'], DocumentSequence::next($acme, 'OC', 'SED-01', 2026));
        $this->assertSame([1, 'RQ-OE-SED-01-000001-2026'], DocumentSequence::next($other, 'RQ', 'SED-01', 2026));
    }

    public function test_authorized_roles_edit_the_code_and_issued_codes_do_not_change(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme', ['company_name' => 'Turismo Negreiros']);
        $this->makeTenant('other', ['company_name' => 'Otra Empresa']);
        $branch = $this->makeBranch($acme, ['code' => 'SED-01']);
        $requester = $this->makeUser($acme, 'supervisor', ['scope_branch_id' => $branch->id]);

        $this->actingAs($requester)->post('/requisitions', [
            'requested_at' => '2026-10-23', 'area' => 'Flota', 'requested_by' => 'Ana Ruiz', 'detail' => 'Llantas', 'classification' => 'REPUESTOS', 'priority' => 'NORMAL',
            'items' => [['product' => 'Llanta 295', 'unit' => 'UNIDAD (BIENES)', 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $issued = Requisition::withoutGlobalScopes()->sole();
        $this->assertSame('RQ-TN-SED-01-000001-' . now()->year, $issued->code());

        $this->actingAs($requester)->put('/requisitions/company-code', ['code' => 'XX'])->assertForbidden();
        $this->actingAs($this->makeUser($acme, 'logistics'))->put('/requisitions/company-code', ['code' => 'XX'])->assertForbidden();

        foreach (['accountant', 'hr_manager', 'company_admin'] as $role) {
            $this->actingAs($this->makeUser($acme, $role))->put('/requisitions/company-code', ['code' => 'oe'])->assertSessionHasErrors('code');
            $this->actingAs($this->makeUser($acme, $role))->put('/requisitions/company-code', ['code' => 'T'])->assertSessionHasErrors('code');
        }
        $this->actingAs($this->makeUser($acme, 'accountant'))->put('/requisitions/company-code', ['code' => 'gn'])->assertSessionHasNoErrors();

        $this->assertSame('GN', $acme->fresh()->code);
        $this->assertSame('RQ-TN-SED-01-000001-' . now()->year, $issued->fresh()->code(), 'lo emitido no cambia');
        $this->assertSame('OC-GN-SED-01-000001-2026', DocumentSequence::next($acme->fresh(), 'OC', 'SED-01', 2026)[1]);
    }

    public function test_migration_style_backfill_avoids_existing_codes(): void
    {
        $this->makeTenant('a', ['company_name' => 'Grupo Negreiros']);
        $this->assertSame('GR', Tenant::uniqueCode('Grupo Negreiros'));
    }
}
