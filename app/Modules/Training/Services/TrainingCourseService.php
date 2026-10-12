<?php

namespace App\Modules\Training\Services;

use App\Modules\Core\Models\User;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingLesson;
use App\Modules\Training\Models\TrainingTarget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guarda un curso completo desde el panel: datos, lecciones (con PDF en el
 * disco privado), preguntas y reglas de asignación. Al quedar publicado se
 * sincronizan las inscripciones (y se avisa a quien recibe el curso).
 */
class TrainingCourseService
{
    public function __construct(private readonly TrainingEnrollmentService $enrollments) {}

    public function disk(): string
    {
        return config('filesystems.documents_disk', 'private');
    }

    /** @param array<string, mixed> $data validado por el controlador */
    public function save(TrainingCourse $course, array $data, User $actor): TrainingCourse
    {
        $uploaded = [];

        try {
            DB::transaction(function () use ($course, $data, $actor, &$uploaded) {
                $wasPublished = $course->exists && $course->isPublished();
                $course->fill(Arr::only($data, ['title', 'description', 'category', 'estimated_minutes', 'is_mandatory', 'due_days', 'passing_score', 'max_attempts', 'status']));
                $course->created_by ??= $actor->id;
                if ($course->isPublished() && ! $wasPublished) {
                    $course->published_at = now();
                }
                $course->save();

                $this->saveLessons($course, $data['lessons'] ?? [], $uploaded);
                $this->saveQuestions($course, $data['questions'] ?? []);
                $this->saveTargets($course, $data['targets'] ?? []);
            });
        } catch (\Throwable $e) {
            foreach ($uploaded as $path) {
                Storage::disk($this->disk())->delete($path);
            }
            throw $e;
        }

        $this->enrollments->syncCourse($course->fresh('targets'));

        return $course;
    }

    private function saveLessons(TrainingCourse $course, array $lessons, array &$uploaded): void
    {
        $keep = [];
        foreach (array_values($lessons) as $i => $row) {
            $lesson = ! empty($row['id'])
                ? TrainingLesson::where('course_id', $course->id)->findOrFail($row['id'])
                : new TrainingLesson(['course_id' => $course->id, 'tenant_id' => $course->tenant_id]);

            $lesson->fill([
                'position'         => $i,
                'title'            => $row['title'],
                'type'             => $row['type'],
                'content'          => $row['type'] === 'text' ? ($row['content'] ?? null) : null,
                'url'              => in_array($row['type'], ['video', 'link'], true) ? ($row['url'] ?? null) : null,
                'duration_minutes' => $row['duration_minutes'] ?? null,
            ]);

            $file = $row['file'] ?? null;
            if ($row['type'] === 'pdf' && $file instanceof UploadedFile) {
                $this->assertPdf($file, $i);
                $old  = $lesson->file_path;
                $path = $file->storeAs("training/{$course->tenant_id}/{$course->id}", Str::uuid() . '.pdf', $this->disk());
                $uploaded[] = $path;
                $lesson->fill(['file_disk' => $this->disk(), 'file_path' => $path, 'file_name' => Str::limit($file->getClientOriginalName(), 200, ''), 'file_size' => $file->getSize()]);
                if ($old) {
                    DB::afterCommit(fn () => Storage::disk($this->disk())->delete($old));
                }
            }
            if ($row['type'] === 'pdf' && ! $lesson->file_path) {
                throw ValidationException::withMessages(["lessons.{$i}.file" => 'Sube el PDF de la lección.']);
            }
            if ($row['type'] !== 'pdf' && $lesson->file_path) {
                $old = $lesson->file_path;
                $lesson->fill(['file_disk' => null, 'file_path' => null, 'file_name' => null, 'file_size' => null]);
                DB::afterCommit(fn () => Storage::disk($this->disk())->delete($old));
            }

            $lesson->save();
            $keep[] = $lesson->id;
        }

        TrainingLesson::where('course_id', $course->id)->whereNotIn('id', $keep)->get()->each(function (TrainingLesson $old) {
            if ($old->file_path) {
                DB::afterCommit(fn () => Storage::disk($old->file_disk ?: $this->disk())->delete($old->file_path));
            }
            $old->delete();
        });
    }

    private function saveQuestions(TrainingCourse $course, array $questions): void
    {
        $course->questions()->delete();
        foreach (array_values($questions) as $i => $row) {
            $options = array_values(array_filter(array_map('trim', $row['options'] ?? []), 'strlen'));
            if (count($options) < 2) {
                throw ValidationException::withMessages(["questions.{$i}.options" => 'Cada pregunta necesita al menos dos opciones.']);
            }
            if (! isset($options[(int) $row['correct_option']])) {
                throw ValidationException::withMessages(["questions.{$i}.correct_option" => 'Marca cuál es la respuesta correcta.']);
            }
            $course->questions()->create([
                'tenant_id'      => $course->tenant_id,
                'position'       => $i,
                'question'       => $row['question'],
                'options'        => $options,
                'correct_option' => (int) $row['correct_option'],
            ]);
        }
    }

    private function saveTargets(TrainingCourse $course, array $targets): void
    {
        $course->targets()->delete();
        $rows = collect($targets)
            ->map(fn ($t) => [
                'target_type'  => $t['type'],
                'target_id'    => in_array($t['type'], ['branch', 'department', 'user'], true) ? (int) ($t['id'] ?? 0) ?: null : null,
                'target_value' => $t['type'] === 'position' ? trim((string) ($t['value'] ?? '')) ?: null : null,
            ])
            ->filter(fn ($t) => $t['target_type'] === 'all' || $t['target_id'] || $t['target_value'])
            ->unique(fn ($t) => implode('|', $t));

        foreach ($rows as $row) {
            $course->targets()->create($row + ['tenant_id' => $course->tenant_id]);
        }
    }

    /** El contenido debe ser un PDF real (no solo la extensión) */
    private function assertPdf(UploadedFile $file, int $index): void
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if ($mime !== 'application/pdf') {
            throw ValidationException::withMessages(["lessons.{$index}.file" => 'El archivo no es un PDF válido.']);
        }
    }
}
