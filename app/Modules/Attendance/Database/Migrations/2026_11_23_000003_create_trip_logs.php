<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control de choferes por viajes (requerimiento Tunesa):
 *  - positions.trip_control: el cargo no marca en la app ni tiene faltas ni tardanzas;
 *    sus días trabajados salen de la Bitácora de viajes.
 *  - tenant_settings.trip_work_days / trip_rest_days: días de trabajo y de descanso al mes (26 y 4).
 *  - trip_logs: el supervisor registra la IDA (fecha, ruta, chofer, ayudante, placa, hora de salida)
 *    y la VUELTA (fecha, hora, ruta y su firma: quién la registró y cuándo).
 *  - Permiso attendance.trips para los roles del sistema que lo tienen en el seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('trip_control')->default(false)->after('is_roaming')->comment('Control por viajes: no marca, sin faltas ni tardanzas');
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('trip_work_days')->nullable()->comment('Choferes: días de trabajo al mes');
            $table->unsignedTinyInteger('trip_rest_days')->nullable()->comment('Choferes: días de descanso al mes');
        });

        Schema::create('trip_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('trip_date')->comment('IDA: fecha');
            $table->string('route', 150)->comment('IDA: ruta');
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('helper_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vehicle_plate', 15);
            $table->time('departure_time')->comment('IDA: hora de salida');
            $table->date('return_date')->nullable()->comment('VUELTA: fecha');
            $table->time('return_time')->nullable()->comment('VUELTA: hora');
            $table->string('return_route', 150)->nullable()->comment('VUELTA: ruta');
            $table->foreignId('return_signed_by')->nullable()->constrained('users')->nullOnDelete()->comment('VUELTA: firma de quien la registra');
            $table->timestamp('return_signed_at')->nullable();
            $table->string('notes', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'trip_date']);
            $table->index(['driver_id', 'trip_date']);
            $table->index(['helper_id', 'trip_date']);
        });

        $permission = Permission::firstOrCreate(['slug' => 'attendance.trips'],
            ['name' => RolesAndPermissionsSeeder::PERMISSIONS['attendance.trips'][0], 'group' => 'attendance']);
        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            if (in_array('attendance.trips', RolesAndPermissionsSeeder::expand($grants), true)) {
                Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                    ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
            }
        }
    }

    public function down(): void
    {
        Permission::where('slug', 'attendance.trips')->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
        Schema::dropIfExists('trip_logs');
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['trip_work_days', 'trip_rest_days']);
        });
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('trip_control');
        });
    }
};
