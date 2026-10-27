<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Auditoría de rendimiento: el PDF de asistencia (se arma entero en memoria) admite hasta 92 días */
class AttendancePdfRangeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_pdf_range_is_capped_and_dates_are_validated(): void
    {
        $this->seedRoles();
        $hr = $this->makeUser($this->makeTenant('acme'), 'hr_manager');
        $this->actingAs($hr);

        $this->get(route('reports.export.pdf', ['start_date' => '2026-07-01', 'end_date' => '2026-09-30']))->assertOk(); // 91 días
        $this->get(route('reports.export.pdf', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31']))->assertSessionHasErrors('end_date');
        $this->get(route('reports.export.pdf', ['start_date' => '2026-09-30', 'end_date' => '2026-09-01']))->assertSessionHasErrors('end_date');
        $this->get(route('reports.export.pdf', ['start_date' => 'no-es-fecha', 'end_date' => '2026-09-30']))->assertSessionHasErrors('start_date');
    }
}
