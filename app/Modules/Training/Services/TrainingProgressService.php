<?php

namespace App\Modules\Training\Services;

use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use App\Modules\Training\Models\TrainingLesson;
use Illuminate\Validation\ValidationException;

/**
 * Avance de un colaborador en un curso.
 *
 * Progreso = lecciones vistas (+ cuestionario aprobado, si hay) sobre el total.
 * Sin cuestionario, el curso se completa al ver la última lección; con
 * cuestionario, al aprobarlo (nota >= passing_score) con todas vistas.
 */
class TrainingProgressService
{
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
     * @param  array<int|string, int>  $answers  id de pregunta => índice de opción elegida
     * @return array{score: int, passed: bool, correct: int, total: int, passing_score: int}
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

        $total   = $course->questions->count();
        $correct = $course->questions->filter(
            fn ($q) => array_key_exists($q->id, $answers) && (int) $answers[$q->id] === (int) $q->correct_option
        )->count();
        $score  = (int) round($correct * 100 / max($total, 1));
        $passed = $score >= $course->passing_score;

        $enrollment->attempts++;
        $enrollment->started_at ??= now();
        // La mejor nota queda registrada; aprobar completa el curso
        $enrollment->score = max((int) $enrollment->score, $score);
        if ($passed && ! $enrollment->completed_at) {
            $enrollment->completed_at = now();
        }
        $enrollment->progress = $this->progress($course, $enrollment);
        $enrollment->save();

        return ['score' => $score, 'passed' => $passed, 'correct' => $correct, 'total' => $total, 'passing_score' => $course->passing_score];
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
