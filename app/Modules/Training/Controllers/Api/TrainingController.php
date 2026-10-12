<?php

namespace App\Modules\Training\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use App\Modules\Training\Models\TrainingLesson;
use App\Modules\Training\Services\TrainingCertificate;
use App\Modules\Training\Services\TrainingProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capacitaciones del colaborador (app móvil). Solo ve los cursos publicados
 * que tiene asignados; las respuestas correctas nunca salen del servidor.
 */
class TrainingController extends Controller
{
    public function __construct(private readonly TrainingProgressService $progress) {}

    /** GET /api/trainings */
    public function index(Request $request): JsonResponse
    {
        $enrollments = TrainingEnrollment::where('user_id', $request->user()->id)
            ->whereHas('course', fn ($q) => $q->published())
            ->with(['course' => fn ($q) => $q->withCount(['lessons', 'questions'])])
            ->get()
            // Pendientes primero (vencidos, por fecha límite), luego completados
            ->sortBy(fn (TrainingEnrollment $e) => [$e->completed_at ? 1 : 0, $e->due_date?->timestamp ?? PHP_INT_MAX, -$e->assigned_at->timestamp])
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $enrollments->map(fn (TrainingEnrollment $e) => $this->summary($e)),
            'meta'    => [
                'pending'   => $enrollments->whereNull('completed_at')->count(),
                'completed' => $enrollments->whereNotNull('completed_at')->count(),
            ],
        ]);
    }

    /** GET /api/trainings/{course} */
    public function show(Request $request, int $course): JsonResponse
    {
        $enrollment = $this->enrollment($request, $course);
        $model      = $enrollment->course->load(['lessons', 'questions']);
        $done       = $enrollment->completedLessonIds();

        return response()->json([
            'success' => true,
            'data'    => $this->summary($enrollment) + [
                'description' => $model->description,
                'lessons'     => $model->lessons->map(fn (TrainingLesson $l) => [
                    'id'               => $l->id,
                    'title'            => $l->title,
                    'type'             => $l->type,
                    'content'          => $l->type === 'text' ? $l->content : null,
                    'url'              => in_array($l->type, ['video', 'link'], true) ? $l->url : null,
                    'has_file'         => $l->type === 'pdf' && filled($l->file_path),
                    'file_name'        => $l->type === 'pdf' ? $l->file_name : null,
                    'duration_minutes' => $l->duration_minutes,
                    'completed'        => in_array($l->id, $done, true),
                ])->values(),
                'questions'   => $model->questions->map(fn ($q) => [
                    'id'       => $q->id,
                    'question' => $q->question,
                    'options'  => array_values($q->options ?? []),
                ])->values(),
            ],
        ]);
    }

    /** POST /api/trainings/{course}/lessons/{lesson}/complete */
    public function completeLesson(Request $request, int $course, int $lesson): JsonResponse
    {
        $enrollment = $this->enrollment($request, $course);
        $model      = TrainingLesson::where('course_id', $course)->findOrFail($lesson);

        $this->progress->completeLesson($enrollment, $model);

        return response()->json(['success' => true, 'data' => $this->summary($enrollment->fresh('course'))]);
    }

    /** POST /api/trainings/{course}/quiz  { answers: { "<question_id>": <índice> } } */
    public function submitQuiz(Request $request, int $course): JsonResponse
    {
        $validated  = $request->validate([
            'answers'   => ['required', 'array', 'max:200'],
            'answers.*' => ['integer', 'between:0,9'],
        ]);
        $enrollment = $this->enrollment($request, $course);

        $result = $this->progress->submitQuiz($enrollment, $validated['answers']);

        return response()->json([
            'success' => true,
            'message' => match (true) {
                $result['passed'] => '¡Aprobaste! Ya puedes descargar tu constancia.',
                $result['locked'] => "Obtuviste {$result['score']} y necesitas {$result['passing_score']}. Agotaste tus intentos: RRHH ya fue avisado y puede darte intentos extra.",
                default           => "Obtuviste {$result['score']} y necesitas {$result['passing_score']}. Te queda" . ($result['attempts_left'] === 1 ? ' 1 intento' : "n {$result['attempts_left']} intentos") . '. Repasa las lecciones antes de volver a intentarlo.',
            },
            'data'    => $result + ['training' => $this->summary($enrollment->fresh('course'))],
        ]);
    }

    /** GET /api/trainings/{course}/lessons/{lesson}/file → PDF de la lección */
    public function lessonFile(Request $request, int $course, int $lesson): Response
    {
        $this->enrollment($request, $course);
        $model = TrainingLesson::where('course_id', $course)->where('type', 'pdf')->findOrFail($lesson);

        abort_unless($model->file_path && Storage::disk($model->file_disk)->exists($model->file_path), 404, 'El documento de esta lección no está disponible.');

        return Storage::disk($model->file_disk)->download($model->file_path, $model->file_name ?: 'leccion.pdf', [
            'Content-Type'  => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** GET /api/trainings/{course}/certificate → constancia en PDF */
    public function certificate(Request $request, int $course, TrainingCertificate $certificate): Response
    {
        $enrollment = $this->enrollment($request, $course);

        if (! $enrollment->completed_at) {
            return response()->json(['success' => false, 'code' => 'TRAINING_NOT_COMPLETED',
                'message' => 'Completa la capacitación para descargar tu constancia.'], 422);
        }

        return response($certificate->render($enrollment), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="constancia_capacitacion_' . $course . '.pdf"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    private function enrollment(Request $request, int $course): TrainingEnrollment
    {
        return TrainingEnrollment::where('user_id', $request->user()->id)
            ->where('course_id', $course)
            ->whereHas('course', fn ($q) => $q->published())
            ->with('course')
            ->firstOrFail();
    }

    private function summary(TrainingEnrollment $e): array
    {
        /** @var TrainingCourse $c */
        $c = $e->course;

        return [
            'id'                => $c->id,
            'title'             => $c->title,
            'category'          => $c->category,
            'category_label'    => $c->categoryLabel(),
            'estimated_minutes' => $c->estimated_minutes,
            'is_mandatory'      => $c->is_mandatory,
            'passing_score'     => $c->passing_score,
            'lessons_count'     => $c->lessons_count ?? $c->lessons()->count(),
            'has_quiz'          => ($c->questions_count ?? $c->questions()->count()) > 0,
            'status'            => $e->status(),
            'status_label'      => TrainingEnrollment::STATUS_LABELS[$e->status()],
            'progress'          => $e->progress,
            'score'             => $e->score,
            'attempts'          => $e->attempts,
            'max_attempts'      => $e->allowedAttempts(),
            'attempts_left'     => $e->completed_at ? 0 : $e->attemptsLeft(),
            'locked'            => $e->isLocked(),
            'assigned_at'       => $e->assigned_at?->toIso8601String(),
            'due_date'          => $e->due_date?->toDateString(),
            'completed_at'      => $e->completed_at?->toIso8601String(),
        ];
    }
}
