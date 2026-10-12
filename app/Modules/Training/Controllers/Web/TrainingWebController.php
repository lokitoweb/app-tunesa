<?php

namespace App\Modules\Training\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use App\Modules\Training\Models\TrainingLesson;
use App\Modules\Training\Models\TrainingTarget;
use App\Modules\Training\Services\TrainingCertificate;
use App\Modules\Training\Services\TrainingCourseService;
use App\Modules\Training\Services\TrainingProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Capacitaciones en el panel: cursos (lecciones, cuestionario, a quién se
 * asignan) y el avance de cada colaborador.
 */
class TrainingWebController extends Controller
{
    public function __construct(
        private readonly TrainingCourseService $courses,
        private readonly AuditLogger $audit,
        private readonly TrainingProgressService $progress,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');

        $courses = TrainingCourse::withCount([
            'lessons', 'questions', 'enrollments',
            'enrollments as completed_count' => fn ($q) => $q->whereNotNull('completed_at'),
            'enrollments as overdue_count'   => fn ($q) => $q->whereNull('completed_at')->whereDate('due_date', '<', now()->toDateString()),
        ])
            ->when(array_key_exists($status, TrainingCourse::STATUSES), fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'published' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->latest('updated_at')
            ->get();

        $totals = TrainingEnrollment::whereHas('course', fn ($q) => $q->published())
            ->selectRaw('COUNT(*) as assigned, SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed')
            ->selectRaw('SUM(CASE WHEN completed_at IS NULL AND due_date < ? THEN 1 ELSE 0 END) as overdue', [now()->toDateString()])
            ->first();

        return Inertia::render('Training/Pages/Courses/Index', [
            'courses' => $courses->map(fn (TrainingCourse $c) => [
                'id'             => $c->id,
                'tenant_id'      => $c->tenant_id,
                'title'          => $c->title,
                'category'       => $c->categoryLabel(),
                'status'         => $c->status,
                'is_mandatory'   => $c->is_mandatory,
                'lessons'        => $c->lessons_count,
                'questions'      => $c->questions_count,
                'assigned'       => $c->enrollments_count,
                'completed'      => $c->completed_count,
                'overdue'        => $c->overdue_count,
                'completion'     => $c->enrollments_count ? (int) round($c->completed_count * 100 / $c->enrollments_count) : 0,
                'updated_at'     => $c->updated_at?->format('d/m/Y'),
            ]),
            'totals'   => ['assigned' => (int) $totals?->assigned, 'completed' => (int) $totals?->completed, 'overdue' => (int) $totals?->overdue],
            'statuses' => TrainingCourse::STATUSES,
            'filter'   => $status,
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (! current_tenant_id()) {
            return redirect()->route('training.index')->with('error', 'Elige una empresa en el selector para crear una capacitación.');
        }

        return $this->form(new TrainingCourse([
            'category' => 'general', 'is_mandatory' => true, 'passing_score' => 70, 'max_attempts' => 3, 'status' => 'draft', 'due_days' => 15,
        ]));
    }

    public function edit(int $id): Response
    {
        return $this->form(TrainingCourse::with(['lessons', 'questions', 'targets'])->findOrFail($id));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id(), 422, 'Elige una empresa para crear la capacitación.');
        $course = $this->courses->save(new TrainingCourse(), $this->validated($request), $request->user());
        $this->audit->logCreated('training.created', $course, ['title' => $course->title]);

        return redirect()->route('training.show', $course->id)->with('success', $this->savedMessage($course, 'creada'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $course = TrainingCourse::findOrFail($id);
        $before = $course->status;
        $this->courses->save($course, $this->validated($request), $request->user());
        $this->audit->log($course->status !== $before && $course->isPublished() ? 'training.published' : 'training.updated', $course, ['title' => $course->title]);

        return redirect()->route('training.show', $course->id)->with('success', $this->savedMessage($course, 'actualizada'));
    }

    /** Publicar o archivar desde el listado o el detalle */
    public function status(Request $request, int $id): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(['published', 'archived', 'draft'])]])['status'];
        $course = TrainingCourse::with('targets')->findOrFail($id);

        if ($status === 'published' && ! $course->lessons()->exists()) {
            return back()->with('error', 'Agrega al menos una lección antes de publicar.');
        }
        if ($status === 'published' && $course->targets->isEmpty()) {
            return back()->with('error', 'Elige a quién se asigna la capacitación antes de publicarla.');
        }

        $course->status = $status;
        if ($status === 'published' && ! $course->published_at) {
            $course->published_at = now();
        }
        $course->save();
        $new = app(\App\Modules\Training\Services\TrainingEnrollmentService::class)->syncCourse($course);
        $this->audit->log($status === 'published' ? 'training.published' : ($status === 'archived' ? 'training.archived' : 'training.updated'), $course, ['title' => $course->title]);

        return back()->with('success', match ($status) {
            'published' => "Capacitación publicada. Se asignó a {$new} persona(s) nueva(s) y se les avisó por la app.",
            'archived'  => 'Capacitación archivada: ya no aparece en la app.',
            default     => 'La capacitación volvió a borrador y ya no aparece en la app.',
        });
    }

    public function destroy(int $id): RedirectResponse
    {
        $course = TrainingCourse::findOrFail($id);
        $course->delete();
        $this->audit->log('training.deleted', $course, ['title' => $course->title]);

        return redirect()->route('training.index')->with('success', 'Capacitación eliminada.');
    }

    public function show(Request $request, int $id): Response
    {
        $course = TrainingCourse::withCount(['lessons', 'questions'])->with('targets')->findOrFail($id);
        $status = $request->query('status');

        $enrollments = TrainingEnrollment::where('course_id', $course->id)
            ->with(['user:id,first_name,last_name,employee_code,position,department_id', 'user.department:id,name', 'user.branches:id,name'])
            ->when($request->query('search'), fn ($q, $s) => $q->whereHas('user', fn ($u) => $u->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%"))))
            ->when($status === 'completed', fn ($q) => $q->whereNotNull('completed_at'))
            ->when($status === 'overdue', fn ($q) => $q->whereNull('completed_at')->whereDate('due_date', '<', now()->toDateString()))
            ->when($status === 'pending', fn ($q) => $q->whereNull('completed_at'))
            ->when($status === 'locked', fn ($q) => $q->whereNull('completed_at')->whereNotNull('locked_at'))
            ->with(['quizAttempts' => fn ($q) => $q->select('id', 'enrollment_id', 'score', 'passed', 'created_at')])
            ->orderByRaw('completed_at IS NOT NULL')->orderBy('due_date')
            ->paginate(25)->withQueryString()
            ->through(fn (TrainingEnrollment $e) => [
                'id'           => $e->id,
                'employee'     => $e->user?->full_name,
                'code'         => $e->user?->employee_code,
                'position'     => $e->user?->position,
                'department'   => $e->user?->department?->name,
                'branch'       => $e->user?->branches->pluck('name')->join(', '),
                'status'       => $e->status(),
                'status_label' => TrainingEnrollment::STATUS_LABELS[$e->status()],
                'progress'     => $e->progress,
                'score'        => $e->score,
                'attempts'     => $e->attempts,
                'max_attempts' => $e->setRelation('course', $course)->allowedAttempts(),
                'locked'       => $e->isLocked(),
                'history'      => $e->quizAttempts->take(10)->map(fn ($a) => [
                    'score' => $a->score, 'passed' => $a->passed, 'at' => $a->created_at?->format('d/m/Y H:i'),
                ])->values(),
                'due_date'     => $e->due_date?->format('d/m/Y'),
                'completed_at' => $e->completed_at?->format('d/m/Y H:i'),
            ]);

        $all = TrainingEnrollment::where('course_id', $course->id);

        return Inertia::render('Training/Pages/Courses/Show', [
            'course' => [
                'id'          => $course->id,
                'title'       => $course->title,
                'description' => $course->description,
                'category'    => $course->categoryLabel(),
                'status'      => $course->status,
                'is_mandatory' => $course->is_mandatory,
                'lessons'     => $course->lessons_count,
                'questions'   => $course->questions_count,
                'passing_score' => $course->passing_score,
                'max_attempts' => $course->max_attempts,
                'due_days'    => $course->due_days,
                'audience'    => $this->describeTargets($course->targets),
            ],
            'stats' => [
                'assigned'  => (clone $all)->count(),
                'completed' => (clone $all)->whereNotNull('completed_at')->count(),
                'overdue'   => (clone $all)->whereNull('completed_at')->whereDate('due_date', '<', now()->toDateString())->count(),
                'avg_score' => (int) round((float) (clone $all)->whereNotNull('score')->avg('score')),
                'locked'    => (clone $all)->whereNull('completed_at')->whereNotNull('locked_at')->count(),
            ],
            'canUnlock'   => $request->user()->can('training.unlock'),
            'enrollments' => $enrollments,
            'filters'     => ['status' => $status, 'search' => $request->query('search')],
        ]);
    }

    /** Intentos extra para quien agotó el cuestionario (desbloquea y le avisa) */
    public function unlock(Request $request, int $id, int $enrollment): RedirectResponse
    {
        $data  = $request->validate(['extra' => ['required', 'integer', 'between:1,5']]);
        $model = TrainingEnrollment::where('course_id', $id)->with(['course', 'user'])->findOrFail($enrollment);
        abort_if($model->completed_at, 422, 'Esta persona ya aprobó la capacitación.');

        $this->progress->unlock($model, $request->user(), (int) $data['extra']);

        return back()->with('success', "Le diste {$data['extra']} intento(s) más a {$model->user->full_name}. Le avisamos en la app.");
    }

    /** CSV con el avance de cada persona (para RRHH o auditorías de SST) */
    public function export(int $id): StreamedResponse
    {
        $course = TrainingCourse::findOrFail($id);
        $rows   = TrainingEnrollment::where('course_id', $course->id)->with(['user:id,first_name,last_name,employee_code,document_number,position'])->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Código', 'Documento', 'Colaborador', 'Cargo', 'Estado', 'Avance %', 'Nota', 'Intentos', 'Intentos permitidos', 'Fecha límite', 'Completado']);
            foreach ($rows as $e) {
                fputcsv($out, [
                    $e->user?->employee_code, $e->user?->document_number, $e->user?->full_name, $e->user?->position,
                    TrainingEnrollment::STATUS_LABELS[$e->status()], $e->progress, $e->score, $e->attempts, $e->setRelation('course', $course)->allowedAttempts(),
                    $e->due_date?->format('d/m/Y'), $e->completed_at?->format('d/m/Y H:i'),
                ]);
            }
            fclose($out);
        }, 'capacitacion_' . $course->id . '_avance.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Vista previa del PDF de una lección */
    public function lessonFile(int $id, int $lesson): HttpResponse
    {
        $model = TrainingLesson::where('course_id', $id)->where('type', 'pdf')->findOrFail($lesson);
        abort_unless($model->file_path && Storage::disk($model->file_disk)->exists($model->file_path), 404);

        return Storage::disk($model->file_disk)->response($model->file_path, $model->file_name, [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Constancia de una persona (RRHH puede reimprimirla) */
    public function certificate(int $id, int $enrollment, TrainingCertificate $certificate): HttpResponse
    {
        $model = TrainingEnrollment::where('course_id', $id)->whereNotNull('completed_at')->findOrFail($enrollment);

        return response($certificate->render($model), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="constancia_' . $model->id . '.pdf"',
        ]);
    }

    private function form(TrainingCourse $course): Response
    {
        return Inertia::render('Training/Pages/Courses/Form', [
            'course' => [
                'id'                => $course->id,
                'title'             => $course->title ?? '',
                'description'       => $course->description ?? '',
                'category'          => $course->category,
                'estimated_minutes' => $course->estimated_minutes,
                'is_mandatory'      => (bool) $course->is_mandatory,
                'due_days'          => $course->due_days,
                'passing_score'     => $course->passing_score,
                'max_attempts'      => $course->max_attempts ?? 3,
                'status'            => $course->status,
                'lessons'           => $course->exists ? $course->lessons->map(fn (TrainingLesson $l) => [
                    'id' => $l->id, 'title' => $l->title, 'type' => $l->type, 'content' => $l->content ?? '', 'url' => $l->url ?? '',
                    'duration_minutes' => $l->duration_minutes, 'file_name' => $l->file_name, 'file' => null,
                ])->values() : [],
                'questions'         => $course->exists ? $course->questions->map(fn ($q) => [
                    'question' => $q->question, 'options' => $q->options, 'correct_option' => $q->correct_option,
                ])->values() : [],
                'targets'           => $course->exists ? $course->targets->map(fn (TrainingTarget $t) => [
                    'type' => $t->target_type, 'id' => $t->target_id, 'value' => $t->target_value,
                ])->values() : [['type' => 'all', 'id' => null, 'value' => null]],
            ],
            'categories'  => TrainingCourse::CATEGORIES,
            'lessonTypes' => TrainingLesson::TYPES,
            'branches'    => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions'   => User::whereNotNull('position')->where('position', '!=', '')->where('status', 'active')
                ->distinct()->orderBy('position')->pluck('position'),
            'employees'   => User::whereIn('role', User::CLOCKING_ROLES)->where('status', 'active')
                ->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'employee_code']),
        ]);
    }

    private function validated(Request $request): array
    {
        $tenant = current_tenant_id();

        return $request->validate([
            'title'                       => ['required', 'string', 'max:200'],
            'description'                 => ['nullable', 'string', 'max:5000'],
            'category'                    => ['required', Rule::in(array_keys(TrainingCourse::CATEGORIES))],
            'estimated_minutes'           => ['nullable', 'integer', 'between:1,2000'],
            'is_mandatory'                => ['boolean'],
            'due_days'                    => ['nullable', 'integer', 'between:1,365'],
            'passing_score'               => ['required', 'integer', 'between:1,100'],
            'max_attempts'                => ['sometimes', 'required', 'integer', 'between:1,10'],
            'status'                      => ['required', Rule::in(array_keys(TrainingCourse::STATUSES))],
            'lessons'                     => [Rule::requiredIf($request->input('status') === 'published'), 'array', 'max:60'],
            'lessons.*.id'                => ['nullable', 'integer'],
            'lessons.*.title'             => ['required', 'string', 'max:200'],
            'lessons.*.type'              => ['required', Rule::in(array_keys(TrainingLesson::TYPES))],
            'lessons.*.content'           => ['nullable', 'required_if:lessons.*.type,text', 'string', 'max:50000'],
            'lessons.*.url'               => ['nullable', 'required_if:lessons.*.type,video', 'required_if:lessons.*.type,link', 'url:https', 'max:500'],
            'lessons.*.duration_minutes'  => ['nullable', 'integer', 'between:1,600'],
            'lessons.*.file'              => ['nullable', 'file', 'mimes:pdf', 'extensions:pdf', 'max:20480'],
            'questions'                   => ['nullable', 'array', 'max:100'],
            'questions.*.question'        => ['required', 'string', 'max:500'],
            'questions.*.options'         => ['required', 'array', 'min:2', 'max:6'],
            'questions.*.options.*'       => ['nullable', 'string', 'max:300'],
            'questions.*.correct_option'  => ['required', 'integer', 'between:0,5'],
            'targets'                     => [Rule::requiredIf($request->input('status') === 'published'), 'array', 'max:200'],
            'targets.*.type'              => ['required', Rule::in(TrainingTarget::TYPES)],
            'targets.*.id'                => ['nullable', 'integer'],
            'targets.*.value'             => ['nullable', 'string', 'max:150'],
        ], [
            'lessons.required'              => 'Agrega al menos una lección antes de publicar.',
            'targets.required'              => 'Elige a quién se asigna la capacitación antes de publicarla.',
            'lessons.*.content.required_if' => 'Escribe el contenido de la lección.',
            'lessons.*.url.required_if'     => 'Pega el enlace del video o la página.',
            'lessons.*.url.url'             => 'El enlace debe empezar con https://.',
            'lessons.*.file.mimes'          => 'Sube un archivo PDF.',
            'lessons.*.file.max'            => 'El PDF no puede pesar más de 20 MB.',
        ]) + $this->validateTargetIds($request, $tenant);
    }

    /** Los ids de sucursal, área o persona deben ser de esta empresa */
    private function validateTargetIds(Request $request, ?int $tenant): array
    {
        foreach ((array) $request->input('targets', []) as $i => $t) {
            $table = ['branch' => 'branches', 'department' => 'departments', 'user' => 'users'][$t['type'] ?? ''] ?? null;
            if ($table && ! DB::table($table)->where('id', (int) ($t['id'] ?? 0))->where('tenant_id', $tenant)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(["targets.{$i}.id" => 'Elige una opción de la lista.']);
            }
        }

        return [];
    }

    private function describeTargets($targets): array
    {
        $branches = Branch::whereIn('id', $targets->where('target_type', 'branch')->pluck('target_id'))->pluck('name', 'id');
        $areas    = Department::whereIn('id', $targets->where('target_type', 'department')->pluck('target_id'))->pluck('name', 'id');
        $people   = User::whereIn('id', $targets->where('target_type', 'user')->pluck('target_id'))->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return $targets->map(fn (TrainingTarget $t) => match ($t->target_type) {
            'all'        => 'Todo el personal',
            'branch'     => 'Sucursal: ' . ($branches[$t->target_id] ?? '—'),
            'department' => 'Área: ' . ($areas[$t->target_id] ?? '—'),
            'position'   => 'Cargo: ' . $t->target_value,
            'user'       => 'Persona: ' . ($people[$t->target_id]?->full_name ?? '—'),
            default      => '',
        })->values()->all();
    }

    private function savedMessage(TrainingCourse $course, string $verb): string
    {
        return $course->isPublished()
            ? "Capacitación {$verb} y publicada: ya aparece en la app de las personas asignadas."
            : "Capacitación {$verb} como {$this->statusName($course)}.";
    }

    private function statusName(TrainingCourse $course): string
    {
        return mb_strtolower(TrainingCourse::STATUSES[$course->status] ?? $course->status);
    }
}
