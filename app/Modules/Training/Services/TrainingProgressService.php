<?php

namespace App\Modules\Training\Services;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Training\Models\TrainingQuizAttempt;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use App\Modules\Training\Models\TrainingLesson;
use App\Modules\Training\Notifications\TrainingLocked;
use App\Modules\Training\Notifications\TrainingUnlocked;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avance de un colaborador en un curso.
 *
 * Progreso = lecciones vistas (+ cuestionario aprobado, si hay) sobre el total.
 * Sin cuestionario, el curso se completa al ver la última lección; con
 * cuestionario, al aprobarlo (nota >= passing_score) con todas vistas, en
 * máximo max_attempts intentos (+ los extra que dé RRHH).
 */
class TrainingProgressService
{
    public const LOCKED_MESSAGE = 'Agotaste los intentos de este cuestionario. RRHH ya fue avisado y puede darte intentos extra.';

    public function completeLesson(TrainingEnrollment $enrollment, TrainingLesson $lesson): TrainingEnrollment
    {
        $course = $enrollment->course()->with(['lessons:id,course_id', 'questions:id,course_id'])->first();

        $done = collect($enrollment->completedLessonIds())->push($lesson->id)->unique()
            ->intersect($course->lessons->pluck('id'))->values()->all();

        $enrollment->completed_lessons = $done;
        $enrollment->started_at ??= now();

        if (! $enrollment->completed_at && ! $course->hasQuiz() && count($done) >= $course->lessons->count()) {
            $enrollment->completed_at = now();
        }
        $enrollment->progress = $this->progress($course, $enrollment);
        $enrollment->save();

        return $enrollment;
    }

    /**
     * Califica un intento. Al fallar el último intento permitido la inscripción
     * se bloquea (423 TRAINING_LOCKED en adelante) y RRHH recibe el aviso.
     *
     * @param  array<int|string, int>  $answers  id de pregunta => índice de opción elegida
     * @return array{score: int, passed: bool, correct: int, total: int, passing_score: int, attempts: int, attempts_left: int, locked: bool}
     */
    public function submitQuiz(TrainingEnrollment $enrollment, array $answers): array
    {
        $course = $enrollment->course()->with(['lessons:id,course_id', 'questions'])->first();

        if (! $course->hasQuiz()) {
            throw ValidationException::withMessages(['answers' => ['Esta capacitación no tiene cuestionario.']]);
        }
        $pending = $course->lessons->pluck('id')->diff($enrollment->completedLessonIds());
        if ($pending->isNotEmpty()) {
            throw ValidationException::withMessages(['answers' => ['Termina todas las lecciones antes de rendir el cuestionario.']]);
        }

        $enrollment->setRelation('course', $course);
        if (! $enrollment->completed_at && ($enrollment->isLocked() || $enrollment->attemptsLeft() === 0)) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code'    => 'TRAINING_LOCKED',
                'message' => self::LOCKED_MESSAGE,
            ], 423));
        }

        $total   = $course->questions->count();
        $correct = $course->questions->filter(
            fn ($q) => array_key_exists($q->id, $answers) && (int) $answers[$q->id] === (int) $q->correct_option
        )->count();
        $score  = (int) round($correct * 100 / max($total, 1));
        $passed = $score >= $course->passing_score;

        $locked = DB::transaction(function () use ($enrollment, $course, $answers, $score, $passed, $correct, $total) {
            $enrollment->attempts++;
            $enrollment->started_at ??= now();
            // La mejor nota queda registrada; aprobar completa el curso
            $enrollment->score = max((int) $enrollment->score, $score);
            if ($passed && ! $enrollment->completed_at) {
                $enrollment->completed_at = now();
            }
            $locked = ! $enrollment->completed_at && $enrollment->attemptsLeft() === 0;
            if ($locked) {
                $enrollment->locked_at = now();
            }
            $enrollment->progress = $this->progress($course, $enrollment);
            $enrollment->save();

            TrainingQuizAttempt::create([
                'tenant_id'     => $enrollment->tenant_id,
                'enrollment_id' => $enrollment->id,
                'course_id'     => $course->id,
                'user_id'       => $enrollment->user_id,
                'score'         => $score,
                'passed'        => $passed,
                'correct'       => $correct,
                'total'         => $total,
                'answers'       => collect($answers)->map(fn ($v) => (int) $v)->all(),
            ]);

            return $locked;
        });

        if ($locked) {
            $this->onLocked($enrollment);
        }

        return [
            'score' => $score, 'passed' => $passed, 'correct' => $correct, 'total' => $total, 'passing_score' => $course->passing_score,
            'attempts' => $enrollment->attempts, 'attempts_left' => $enrollment->completed_at ? 0 : $enrollment->attemptsLeft(), 'locked' => $locked,
        ];
    }

    /** RRHH da intentos extra y desbloquea */
    public function unlock(TrainingEnrollment $enrollment, User $by, int $extra): void
    {
        $enrollment->loadMissing('course', 'user');
        // Los intentos extra se suman a los ya usados: quedan exactamente $extra disponibles
        $enrollment->extra_attempts = max(0, (int) $enrollment->attempts - (int) $enrollment->course->max_attempts) + $extra;
        $enrollment->locked_at   = null;
        $enrollment->unlocked_by = $by->id;
        $enrollment->unlocked_at = now();
        $enrollment->save();

        app(AuditLogger::class)->log('training.unlocked', $enrollment->user, [
            'name' => $enrollment->user->full_name, 'course' => $enrollment->course->title, 'extra_attempts' => $extra,
        ]);
        $this->safely(fn () => $enrollment->user->notify(new TrainingUnlocked($enrollment, $extra)));
    }

    private function onLocked(TrainingEnrollment $enrollment): void
    {
        $enrollment->loadMissing('user', 'course');
        app(AuditLogger::class)->log('training.locked', $enrollment->user, [
            'name' => $enrollment->user->full_name, 'course' => $enrollment->course->title, 'attempts' => $enrollment->attempts, 'score' => $enrollment->score,
        ]);

        $this->safely(function () use ($enrollment) {
            User::withoutGlobalScopes()->where('tenant_id', $enrollment->tenant_id)->where('status', 'active')
                ->whereIn('role', ['company_admin', 'hr_manager'])->get()
                ->filter(fn (User $u) => $u->hasPermission('training.unlock'))
                ->each(fn (User $u) => $u->notify((new TrainingLocked($enrollment))));
        });
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function progress(TrainingCourse $course, TrainingEnrollment $enrollment): int
    {
        if ($enrollment->completed_at) {
            return 100;
        }
        $steps = $course->lessons->count() + ($course->hasQuiz() ? 1 : 0);
        $done  = count(array_intersect($enrollment->completedLessonIds(), $course->lessons->pluck('id')->all()));

        return $steps === 0 ? 0 : min(99, (int) floor($done * 100 / $steps));
    }
}
