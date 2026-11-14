<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varios supervisores por sede (turno mañana y tarde, o dos a la vez). Fuente única de quién
 * supervisa cada sede (Branch::supervisors / Branch::scopeSupervisedBy). branches.supervisor_id
 * se mantiene como el primero de la lista, solo para lo que aún muestra un nombre (API antigua).
 * Se copian los supervisores que ya había: nadie pierde lo asignado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
            $table->index('user_id');
        });

        $now = now();
        DB::table('branches')->whereNotNull('supervisor_id')->orderBy('id')
            ->get(['id', 'supervisor_id'])
            ->each(fn ($b) => DB::table('branch_supervisors')->insertOrIgnore([
                'branch_id' => $b->id, 'user_id' => $b->supervisor_id, 'created_at' => $now, 'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_supervisors');
    }
};
