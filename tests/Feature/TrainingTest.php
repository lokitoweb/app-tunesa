<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Capacitaciones: panel (cursos y asignación), app (avance, cuestionario, constancia) y aislamiento */
class TrainingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $admin;

    private Branch $lima;

    private Branch $arequipa;

    private User $emp;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seedRoles();
        $this->acme     = $this->makeTenant('acme');
        $this->admin    = $this->makeUser($this->acme, 'company_admin');
        $this->lima     = $this->makeBranch($this->acme, ['name' => 'Lima']);
        $this->arequipa = $this->makeBranch($this->acme, ['name' => 'Arequipa']);
        $this->emp      = $this->makeUser($this->acme, 'employee', ['position' => 'Chofer']);
        $this->other    = $this->makeUser($this->acme, 'employee', ['position' => 'Almacenero']);
        $this->emp->branches()->attach($this->lima->id, ['is_primary' => true]);
        $this->other->branches()->attach($this->arequipa->id, ['is_primary' => true]);
        app(TenantContext::class)->clear();
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Manejo defensivo', 'description' => 'Para choferes', 'category' => 'seguridad',
            'estimated_minutes' => 30, 'is_mandatory' => '1', 'due_days' => 10, 'passing_score' => 70, 'status' => 'published',
            'lessons' => [
                ['title' => 'Introducción', 'type' => 'text', 'content' => 'Bienvenido'],
                ['title' => 'Manual', 'type' => 'pdf', 'file' => $this->pdf()],
                ['title' => 'Video', 'type' => 'video', 'url' => 'https://www.youtube.com/watch?v=abc'],
            ],
            'questions' => [
                ['question' => '¿Velocidad máxima en ciudad?', 'options' => ['30', '50', '90'], 'correct_option' => 1],
                ['question' => '¿Cinturón obligatorio?', 'options' => ['Sí', 'No'], 'correct_option' => 0],
            ],
            'targets' => [['type' => 'branch', 'id' => $this->lima->id]],
        ], $overrides);
    }

    private function createCourse(array $overrides = []): TrainingCourse
    {
        $this->actingAs($this->admin)->post('/training', $this->payload($overrides))->assertSessionHasNoErrors()->assertRedirect();
        app(TenantContext::class)->clear();

        return TrainingCourse::latest('id')->firstOrFail();
    }

    public function test_publishing_assigns_by_branch_and_notifies(): void
    {
        $course = $this->createCourse();

        $this->assertSame(3, $course->lessons()->count());
        $this->assertSame(2, $course->questions()->count());
        $this->assertSame([$this->emp->id], TrainingEnrollment::pluck('user_id')->all());
        $this->assertSame(now()->addDays(10)->toDateString(), TrainingEnrollment::first()->due_date->toDateString());

        $note = $this->emp->notifications()->where('type', 'training.assigned')->sole();
        $this->assertSame('Nueva capacitación obligatoria', $note->data['title']);
        $this->assertSame('trainings', $note->data['data']['screen']);
        $this->assertSame(0, $this->other->notifications()->count());

        $pdf = $course->lessons()->where('type', 'pdf')->first();
        Storage::disk('private')->assertExists($pdf->file_path);
    }

    public function test_drafts_are_not_assigned_and_publish_requires_lessons_and_audience(): void
    {
        $course = $this->createCourse(['status' => 'draft']);
        $this->assertSame(0, TrainingEnrollment::count());

        $this->actingAs($this->admin)->post('/training', $this->payload(['lessons' => [], 'targets' => []]))
            ->assertSessionHasErrors(['lessons', 'targets']);

        $this->actingAs($this->admin)->post("/training/{$course->id}/status", ['status' => 'published'])->assertRedirect();
        $this->assertSame(1, TrainingEnrollment::count());
    }

    public function test_position_targets_ignore_case_and_new_staff_are_enrolled(): void
    {
        $this->createCourse(['targets' => [['type' => 'position', 'value' => 'chofer ']]]);
        $this->assertSame([$this->emp->id], TrainingEnrollment::pluck('user_id')->all());

        // Alta posterior con el mismo cargo: queda asignada sola
        $new = $this->makeUser($this->acme, 'employee', ['position' => 'CHOFER']);
        app(TenantContext::class)->clear();
        $this->assertTrue(TrainingEnrollment::where('user_id', $new->id)->exists());

        // Cambio de cargo también
        $this->other->update(['position' => 'Chofer']);
        $this->assertTrue(TrainingEnrollment::where('user_id', $this->other->id)->exists());
    }

    public function test_branch_assignment_after_publishing_enrolls(): void
    {
        $this->createCourse();
        $this->other->branches()->attach($this->lima->id);
        app(TenantContext::class)->clear();

        $this->assertTrue(TrainingEnrollment::where('user_id', $this->other->id)->exists());
    }

    public function test_employee_completes_lessons_quiz_and_downloads_certificate(): void
    {
        $course  = $this->createCourse();
        $lessons = $course->lessons()->pluck('id');

        $list = $this->actingIn($this->emp, $this->acme)->getJson('/api/trainings')->assertOk();
        $list->assertJsonPath('data.0.title', 'Manejo defensivo')->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('meta.pending', 1);

        $detail = $this->actingIn($this->emp, $this->acme)->getJson("/api/trainings/{$course->id}")->assertOk();
        $this->assertCount(3, $detail->json('data.lessons'));
        $this->assertSame(['30', '50', '90'], $detail->json('data.questions.0.options'));
        $this->assertStringNotContainsString('correct_option', $detail->getContent());
        $this->assertTrue($detail->json('data.lessons.1.has_file'));

        $this->actingIn($this->emp, $this->acme)->get("/api/trainings/{$course->id}/lessons/{$lessons[1]}/file")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Cuestionario antes de terminar las lecciones: no
        $q = $course->questions()->pluck('id');
        $this->actingIn($this->emp, $this->acme)->postJson("/api/trainings/{$course->id}/quiz", ['answers' => [$q[0] => 1, $q[1] => 0]])
            ->assertStatus(422);

        foreach ($lessons as $i => $id) {
            $res = $this->actingIn($this->emp, $this->acme)->postJson("/api/trainings/{$course->id}/lessons/{$id}/complete")->assertOk();
        }
        $res->assertJsonPath('data.progress', 75)->assertJsonPath('data.status', 'in_progress');

        $this->actingIn($this->emp, $this->acme)->getJson("/api/trainings/{$course->id}/certificate")
            ->assertStatus(422)->assertJsonPath('code', 'TRAINING_NOT_COMPLETED');

        // Desaprueba (50) y luego aprueba (100)
        $this->actingIn($this->emp, $this->acme)->postJson("/api/trainings/{$course->id}/quiz", ['answers' => [$q[0] => 0, $q[1] => 0]])
            ->assertOk()->assertJsonPath('data.passed', false)->assertJsonPath('data.score', 50);
        $this->actingIn($this->emp, $this->acme)->postJson("/api/trainings/{$course->id}/quiz", ['answers' => [$q[0] => 1, $q[1] => 0]])
            ->assertOk()->assertJsonPath('data.passed', true)->assertJsonPath('data.training.status', 'completed')
            ->assertJsonPath('data.training.progress', 100)->assertJsonPath('data.training.attempts', 2);

        $pdf = $this->actingIn($this->emp, $this->acme)->get("/api/trainings/{$course->id}/certificate")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // RRHH ve el avance y la constancia
        app(TenantContext::class)->clear();
        $enrollment = TrainingEnrollment::sole();
        $this->actingAs($this->admin)->get("/training/{$course->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Training/Pages/Courses/Show')
                ->where('stats.completed', 1)->where('enrollments.data.0.score', 100));
        $this->actingAs($this->admin)->get("/training/{$course->id}/certificates/{$enrollment->id}")->assertOk();
        $this->actingAs($this->admin)->get("/training/{$course->id}/export")->assertOk();
    }

    public function test_quiz_locks_after_max_attempts_alerts_hr_and_hr_grants_extra(): void
    {
        $hr     = $this->makeUser($this->acme, 'hr_manager');
        $course = $this->createCourse(['max_attempts' => 2]);
        $this->assertSame(2, $course->max_attempts);
        $q = $course->questions()->pluck('id');
        foreach ($course->lessons()->pluck('id') as $id) {
            $this->actingIn($this->emp, $this->acme)->postJson("/api/trainings/{$course->id}/lessons/{$id}/complete")->assertOk();
        }
        $quiz = fn (array $answers) => $this->actingIn($this->emp, $this->acme)->postJson("/api/trainings/{$course->id}/quiz", ['answers' => $answers]);
        $fail = [$q[0] => 0, $q[1] => 1];

        $quiz($fail)->assertOk()->assertJsonPath('data.attempts_left', 1)->assertJsonPath('data.locked', false)
            ->assertJsonPath('message', 'Obtuviste 0 y necesitas 70. Te queda 1 intento. Repasa las lecciones antes de volver a intentarlo.');
        $quiz($fail)->assertOk()->assertJsonPath('data.locked', true)->assertJsonPath('data.attempts_left', 0)
            ->assertJsonPath('data.training.status', 'locked')->assertJsonPath('data.training.locked', true);

        // Bloqueado: ni aprobando entra
        $quiz([$q[0] => 1, $q[1] => 0])->assertStatus(423)->assertJsonPath('code', 'TRAINING_LOCKED');

        app(TenantContext::class)->clear();
        $enrollment = TrainingEnrollment::sole();
        $this->assertNotNull($enrollment->locked_at);
        $this->assertSame(2, $enrollment->quizAttempts()->count());
        $this->assertSame([0, 0], $enrollment->quizAttempts()->pluck('score')->all());
        $this->assertSame(1, $hr->notifications()->where('type', 'training.locked')->count());
        $this->assertSame(1, $this->admin->notifications()->where('type', 'training.locked')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'training.locked', 'subject_id' => $this->emp->id]);

        // Panel: aparece bloqueado con su historial; un supervisor no puede desbloquear
        $this->actingAs($hr)->get("/training/{$course->id}?status=locked")->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.locked', 1)->where('canUnlock', true)
                ->where('enrollments.data.0.locked', true)->where('enrollments.data.0.max_attempts', 2)
                ->has('enrollments.data.0.history', 2));
        $sup = $this->makeUser($this->acme, 'supervisor');
        app(TenantContext::class)->clear();
        $this->actingAs($sup)->post("/training/{$course->id}/enrollments/{$enrollment->id}/unlock", ['extra' => 1])->assertForbidden();

        $this->actingAs($hr)->post("/training/{$course->id}/enrollments/{$enrollment->id}/unlock", ['extra' => 1])->assertRedirect();
        app(TenantContext::class)->clear();
        $enrollment->refresh();
        $this->assertNull($enrollment->locked_at);
        $this->assertSame($hr->id, $enrollment->unlocked_by);
        $this->assertSame(1, $enrollment->attemptsLeft());
        $this->assertSame(1, $this->emp->notifications()->where('type', 'training.unlocked')->count());

        $quiz([$q[0] => 1, $q[1] => 0])->assertOk()->assertJsonPath('data.passed', true)->assertJsonPath('data.training.status', 'completed');
    }

    public function test_course_without_quiz_completes_with_last_lesson(): void
    {
        $course = $this->createCourse(['questions' => [], 'lessons' => [['title' => 'Única', 'type' => 'text', 'content' => 'x']]]);
        $lesson = $course->lessons()->value('id');

        $this->actingIn($this->emp, $this->acme)->postJson("/api/trainings/{$course->id}/lessons/{$lesson}/complete")
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.progress', 100);
    }

    public function test_unassigned_archived_and_other_tenants_cannot_see_the_course(): void
    {
        $course = $this->createCourse();

        $this->actingIn($this->other, $this->acme)->getJson("/api/trainings/{$course->id}")->assertNotFound();
        $this->actingIn($this->other, $this->acme)->getJson('/api/trainings')->assertOk()->assertJsonCount(0, 'data');

        app(TenantContext::class)->clear();
        $globex = $this->makeTenant('globex');
        $stranger = $this->makeUser($globex, 'employee');
        app(TenantContext::class)->clear();
        $this->actingIn($stranger, $globex)->getJson("/api/trainings/{$course->id}")->assertNotFound();
        $strangerAdmin = $this->makeUser($globex, 'company_admin');
        app(TenantContext::class)->clear();
        $this->actingAs($strangerAdmin)->get("/training/{$course->id}")->assertNotFound();

        $this->actingAs($this->admin)->post("/training/{$course->id}/status", ['status' => 'archived']);
        $this->actingIn($this->emp, $this->acme)->getJson('/api/trainings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_managers_open_the_panel_and_pdf_content_is_checked(): void
    {
        $supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->actingAs($supervisor)->get('/training')->assertForbidden();
        $this->actingAs($this->admin)->get('/training')->assertOk()->assertInertia(fn ($page) => $page->component('Training/Pages/Courses/Index'));
        $this->actingAs($this->admin)->get('/training/create')->assertOk();

        $fake = UploadedFile::fake()->createWithContent('virus.pdf', '<?php echo 1;');
        $this->actingAs($this->admin)->post('/training', $this->payload(['lessons' => [['title' => 'x', 'type' => 'pdf', 'file' => $fake]]]))
            ->assertSessionHasErrors('lessons.0.file');
        $this->actingAs($this->admin)->post('/training', $this->payload(['lessons' => [['title' => 'x', 'type' => 'video', 'url' => 'http://inseguro.test']]]))
            ->assertSessionHasErrors('lessons.0.url');
        app(TenantContext::class)->clear();
        $globex = $this->makeTenant('globex');
        $foreignBranch = $this->makeBranch($globex);
        app(TenantContext::class)->clear();
        $this->actingAs($this->admin)->post('/training', $this->payload(['targets' => [['type' => 'branch', 'id' => $foreignBranch->id]]]))
            ->assertSessionHasErrors('targets.0.id');
    }

    public function test_editing_keeps_progress_and_replaces_lessons(): void
    {
        $course = $this->createCourse();
        $lessons = $course->lessons()->get();
        $this->actingIn($this->emp, $this->acme)->postJson("/api/trainings/{$course->id}/lessons/{$lessons[0]->id}/complete")->assertOk();
        app(TenantContext::class)->clear();

        $this->actingAs($this->admin)->post("/training/{$course->id}", [
            '_method' => 'put',
        ] + $this->payload([
            'lessons' => [
                ['id' => $lessons[0]->id, 'title' => 'Introducción 2', 'type' => 'text', 'content' => 'Nuevo'],
                ['id' => $lessons[1]->id, 'title' => 'Manual', 'type' => 'pdf'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['Introducción 2', 'Manual'], $course->lessons()->pluck('title')->all());
        $this->assertSame([$lessons[0]->id], TrainingEnrollment::sole()->completedLessonIds());
    }

    public function test_due_soon_reminder_is_sent_once(): void
    {
        $this->createCourse(['due_days' => 2]);

        $this->artisan('training:sync')->assertSuccessful();
        $this->artisan('training:sync')->assertSuccessful();

        $this->assertSame(1, $this->emp->notifications()->where('type', 'training.due_soon')->count());
    }

    public function test_module_switch_reaches_the_app_config(): void
    {
        $this->getJson('/api/v1/app/config?tenant_slug=acme')->assertOk()->assertJsonPath('data.modules.trainings', true);

        $this->acme->update(['settings' => ['disabled_modules' => ['Training']]]);
        $this->getJson('/api/v1/app/config?tenant_slug=acme')->assertOk()->assertJsonPath('data.modules.trainings', false);
        $this->actingIn($this->emp, $this->acme)->getJson('/api/trainings')->assertStatus(403);
    }
}
