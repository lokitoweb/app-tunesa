<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cargos de la empresa (Atención al cliente, Almacenero, Chofer, Estibador...)
 * y la plantilla de cada sede: qué cargos tiene y cuántas plazas de cada uno.
 *
 * users.position (texto) se conserva como copia del nombre del cargo para los
 * reportes, exportaciones y la app; users.position_id es el vínculo real.
 * Los cargos que ya estaban escritos a mano pasan al catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->string('description', 255)->nullable();
            // Choferes, repartidores, relevos: al asignarlo se activa la marcación itinerante
            $table->boolean('is_roaming')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('branch_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            // Plazas previstas del cargo en la sede (dotación planificada)
            $table->unsignedSmallInteger('planned_headcount')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'position_id']);
            $table->index(['tenant_id', 'position_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->index(['tenant_id', 'position_id'], 'users_tenant_position_idx');
        });

        $this->backfillFromFreeText();
    }

    /** Los cargos escritos a mano en cada colaborador pasan al catálogo de su empresa */
    private function backfillFromFreeText(): void
    {
        $rows = DB::table('users')
            ->whereNotNull('tenant_id')->whereNotNull('position')->where('position', '!=', '')
            ->select('tenant_id', 'position')->distinct()->get();

        $now = now();
        foreach ($rows as $row) {
            $name = mb_substr(trim($row->position), 0, 100);
            if ($name === '') {
                continue;
            }
            $id = DB::table('positions')->where('tenant_id', $row->tenant_id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id')
                ?? DB::table('positions')->insertGetId(['tenant_id' => $row->tenant_id, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

            DB::table('users')->where('tenant_id', $row->tenant_id)->where('position', $row->position)->update(['position_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_tenant_position_idx');
            $table->dropConstrainedForeignId('position_id');
        });
        Schema::dropIfExists('branch_positions');
        Schema::dropIfExists('positions');
    }
};
