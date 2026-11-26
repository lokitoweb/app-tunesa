<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de viajes, versión del ingeniero de Tunesa:
 *  - trip_number: N.º de salida correlativo por empresa (lo pone el sistema).
 *  - co_driver_id: Conductor 2. helper_id pasa a ser el Auxiliar de carga.
 *  - arrival_*: llegada de la IDA. return_departure_*: salida del REGRESO (return_date/time es su llegada).
 *    Las horas de conducción salen de salida → llegada de cada tramo y las heredan los tres del viaje.
 *  - trip_sheets.leg: la hoja firmada subida es la de conducción de ida o la de regreso.
 * Viajes existentes: el «Ayudante» registrado era el segundo chofer (cargo CHOFER), pasa a Conductor 2
 * (confirmado por el dueño); se numeran en el orden en que se registraron. No se pierde ningún dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_logs', function (Blueprint $table) {
            $table->unsignedInteger('trip_number')->nullable()->after('tenant_id')->comment('N.º de salida correlativo por empresa');
            $table->foreignId('co_driver_id')->nullable()->after('driver_id')->constrained('users')->nullOnDelete()->comment('Conductor 2');
            $table->date('arrival_date')->nullable()->after('departure_time')->comment('IDA: fecha de llegada');
            $table->time('arrival_time')->nullable()->after('arrival_date')->comment('IDA: hora de llegada');
            $table->date('return_departure_date')->nullable()->after('arrival_time')->comment('REGRESO: fecha de salida');
            $table->time('return_departure_time')->nullable()->after('return_departure_date')->comment('REGRESO: hora de salida');
            $table->unique(['tenant_id', 'trip_number']);
            $table->index(['co_driver_id', 'trip_date']);
        });

        Schema::table('trip_sheets', function (Blueprint $table) {
            $table->string('leg', 10)->nullable()->after('period_to')->comment('Hoja de conducción: ida o regreso');
        });

        // El ayudante registrado hasta hoy era el 2.º chofer: pasa a Conductor 2; el auxiliar queda libre
        DB::table('trip_logs')->whereNotNull('helper_id')->update(['co_driver_id' => DB::raw('helper_id'), 'helper_id' => null]);

        // N.º de salida de los viajes que ya existen: en el orden en que se registraron, por empresa
        $next = [];
        DB::table('trip_logs')->orderBy('tenant_id')->orderBy('created_at')->orderBy('id')->get(['id', 'tenant_id'])
            ->each(function ($row) use (&$next) {
                $next[$row->tenant_id] = ($next[$row->tenant_id] ?? 0) + 1;
                DB::table('trip_logs')->where('id', $row->id)->update(['trip_number' => $next[$row->tenant_id]]);
            });
    }

    public function down(): void
    {
        DB::table('trip_logs')->whereNotNull('co_driver_id')->whereNull('helper_id')->update(['helper_id' => DB::raw('co_driver_id')]);
        Schema::table('trip_sheets', fn (Blueprint $table) => $table->dropColumn('leg'));
        Schema::table('trip_logs', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'trip_number']);
            $table->dropIndex(['co_driver_id', 'trip_date']);
            $table->dropConstrainedForeignId('co_driver_id');
            $table->dropColumn(['trip_number', 'arrival_date', 'arrival_time', 'return_departure_date', 'return_departure_time']);
        });
    }
};
