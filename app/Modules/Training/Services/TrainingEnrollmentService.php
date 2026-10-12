<?php

namespace App\Modules\Training\Services;

use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\User;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use App\Modules\Training\Models\TrainingTarget;
use App\Modules\Training\Notifications\TrainingAssigned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Convierte las reglas de asignación (TrainingTarget) en inscripciones por
 * persona y avisa (in-app + push) a quien recibe un curso nuevo.
 *
 * - Al publicar o cambiar las reglas de un curso: syncCourse().
 * - Al crear a alguien o cambiarle sucursal, área o cargo: syncUser().
 * - Cada madrugada: training:sync (por si algo cambió por otra vía).
 *
 * Nunca borra un avance: solo quita inscripciones sin empezar que ya no
 * corresponden (p. ej. se quitó una sucursal de la regla).
 */
class TrainingEnrollmentService
{
    /** Personas a las que aplica el curso según sus reglas */
    public function audience(TrainingCourse $course): Builder
    {
        $targets = $course->relationLoaded('targets') ? $course->targets : $course->targets()->get();

        $query = User::withoutGlobalScope(TenantScope::class)
            ->where('users.tenant_id', $course->tenant_id)
            ->where('users.status', 'active')
            ->whereIn('users.role', User::CLOCKING_ROLES);

        if ($targets->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }
        if ($targets->contains('target_type', 'all')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($targets) {
            foreach ($targets as $t) {
                match ($t->target_type) {
                    'user'       => $q->orWhere('users.id', $t->target_id),
                    'department' => $q->orWhere('users.department_id', $t->target_id),
                    'position'   => $q->orWhereRaw('LOWER(TRIM(users.position)) = ?', [mb_strtolower(trim((string) $t->target_value))]),
                    'branch'     => $q->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('employee_branch')
                        ->whereColumn('employee_branch.user_id', 'users.id')
                        ->where('employee_branch.branch_id', $t->target_id)
                        ->where(fn ($w) => $w->whereNull('employee_branch.assigned_until')->orWhere('employee_branch.assigned_until', '>=', now()->toDateString()))),
                    default      => null,
                };
            }
        });
    }

    /** @return int Inscripciones nuevas */
    public function syncCourse(TrainingCourse $course): int
    {
        if (! $course->isPublished()) {
            return 0;
        }

        $userIds  = $this->audience($course)->pluck('users.id');
        $existing = TrainingEnrollment::withoutGlobalScope(TenantScope::class)->where('course_id', $course->id)->pluck('user_id');

        // Sin empezar y fuera de la audiencia: se quita
        TrainingEnrollment::withoutGlobalScope(TenantScope::class)
            ->where('course_id', $course->id)
            ->whereNotIn('user_id', $userIds)
            ->whereNull('started_at')->whereNull('completed_at')
            ->delete();

        $new = $userIds->diff($existing);
        $this->enroll($course, $new);

        return $new->count();
    }

    /** Cursos publicados que le corresponden a esta persona y aún no tiene */
    public function syncUser(User $user): int
    {
        if ($user->status !== 'active' || ! in_array($user->role, User::CLOCKING_ROLES, true) || ! $user->tenant_id) {
            return 0;
        }

        $courses = TrainingCourse::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $user->tenant_id)->published()
            ->whereDoesntHave('enrollments', fn ($q) => $q->withoutGlobalScope(TenantScope::class)->where('user_id', $user->id))
            ->with('targets')
            ->get();

        $count = 0;
        foreach ($courses as $course) {
            if ($this->audience($course)->where('users.id', $user->id)->exists()) {
                $this->enroll($course, collect([$user->id]));
                $count++;
            }
        }

        return $count;
    }

    private function enroll(TrainingCourse $course, Collection $userIds): void
    {
        if ($userIds->isEmpty()) {
            return;
        }

        $now = now();
        $due = $course->due_days ? $now->copy()->addDays($course->due_days)->toDateString() : null;

        foreach ($userIds->chunk(500) as $chunk) {
            TrainingEnrollment::withoutGlobalScope(TenantScope::class)->insertOrIgnore($chunk->map(fn ($id) => [
                'tenant_id'   => $course->tenant_id,
                'course_id'   => $course->id,
                'user_id'     => $id,
                'assigned_at' => $now,
                'due_date'    => $due,
                'progress'    => 0,
                'attempts'    => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ])->values()->all());
        }

        try {
            User::withoutGlobalScope(TenantScope::class)->whereIn('id', $userIds)
                ->chunkById(200, fn ($users) => \Illuminate\Support\Facades\Notification::send($users, new TrainingAssigned($course, $due)));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar la capacitación asignada: ' . $e->getMessage(), ['course_id' => $course->id]);
        }
    }
}
