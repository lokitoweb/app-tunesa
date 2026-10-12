<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuestionarios con tope de intentos: al fallar el último, la inscripción se
 * bloquea y RRHH recibe el aviso; RRHH puede dar intentos extra. Cada intento
 * queda en training_quiz_attempts para auditoría.
 *
 * Quien ya tenía intentos fallidos no queda bloqueado de golpe: sus intentos
 * anteriores se compensan con extra_attempts (arranca con el tope completo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_attempts')->default(3)->after('passing_score');
        });

        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->unsignedSmallInteger('extra_attempts')->default(0)->after('attempts');
            $table->timestamp('locked_at')->nullable()->after('extra_attempts');
            $table->foreignId('unlocked_by')->nullable()->after('locked_at')->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable()->after('unlocked_by');
        });

        Schema::create('training_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('training_enrollments')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->boolean('passed');
            $table->unsignedSmallInteger('correct');
            $table->unsignedSmallInteger('total');
            $table->json('answers');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['enrollment_id', 'created_at']);
        });

        DB::table('training_enrollments')->whereNull('completed_at')->where('attempts', '>', 0)
            ->update(['extra_attempts' => DB::raw('attempts')]);

        // Permiso para desbloquear (mismo patrón que add_training_permissions)
        [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS['training.unlock'];
        $id = Permission::updateOrCreate(['slug' => 'training.unlock'], ['name' => $name, 'group' => $group])->id;
        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            if (in_array('training.unlock', RolesAndPermissionsSeeder::expand($grants), true)) {
                Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                    ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$id]));
            }
        }
    }

    public function down(): void
    {
        Permission::where('slug', 'training.unlock')->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
        Schema::dropIfExists('training_quiz_attempts');
        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unlocked_by');
            $table->dropColumn(['extra_attempts', 'locked_at', 'unlocked_at']);
        });
        Schema::table('training_courses', fn (Blueprint $table) => $table->dropColumn('max_attempts'));
    }
};
