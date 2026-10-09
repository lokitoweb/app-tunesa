<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora inmutable de marcaciones (una fila por evento: entrada, inicio y
 * fin de descanso, salida). `attendances` es el resumen de la jornada; esta
 * tabla es la evidencia: nunca se edita ni se borra.
 *
 * Única excepción: `address`, que llena después el job ReverseGeocodePunch.
 * Además del bloqueo en el modelo (AttendancePunch), un trigger de base de
 * datos rechaza cualquier UPDATE de otra columna (SQLite, MySQL/MariaDB y
 * PostgreSQL). No hay trigger de DELETE para que el borrado en cascada de una
 * empresa siga funcionando.
 *
 * Fechas: device_time, server_time y offline_recorded_at se guardan en UTC.
 */
return new class extends Migration
{
    /** Columnas que el trigger protege (todas menos id y address) */
    private const IMMUTABLE = [
        'tenant_id', 'attendance_id', 'user_id', 'branch_id', 'type', 'client_uuid',
        'latitude', 'longitude', 'accuracy_meters', 'distance_meters', 'within_geofence',
        'device_time', 'server_time', 'time_drift_seconds', 'offline', 'offline_recorded_at',
        'photo_path', 'face_detected', 'mock_location_detected', 'suspicious', 'suspicion_reasons',
        'device_id', 'device_os', 'app_version', 'ip', 'created_at',
    ];

    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('attendance_id')->constrained('attendances')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->enum('type', ['clock_in', 'break_start', 'break_end', 'clock_out']);
            $table->uuid('client_uuid')->nullable()->comment('Idempotencia: único por empresa');

            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->boolean('within_geofence')->nullable();
            $table->string('address', 500)->nullable()->comment('Geocodificación inversa (async)');

            $table->dateTime('device_time')->nullable()->comment('UTC, reloj del teléfono');
            $table->dateTime('server_time')->comment('UTC, recepción en el servidor');
            $table->integer('time_drift_seconds')->nullable()->comment('device - server');
            $table->boolean('offline')->default(false);
            $table->dateTime('offline_recorded_at')->nullable()->comment('UTC');

            $table->string('photo_path')->nullable()->comment('Disco privado (ATTENDANCE_DISK)');
            $table->boolean('face_detected')->nullable();
            $table->boolean('mock_location_detected')->default(false);
            $table->boolean('suspicious')->default(false);
            $table->json('suspicion_reasons')->nullable();

            $table->string('device_id')->nullable();
            $table->string('device_os', 20)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'client_uuid'], 'attendance_punches_tenant_client_uuid_unique');
            $table->index(['tenant_id', 'user_id', 'server_time']);
            $table->index(['attendance_id', 'type']);
            $table->index(['tenant_id', 'suspicious']);
        });

        $this->createImmutabilityTrigger();
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS attendance_punches_immutable ON attendance_punches');
            DB::unprepared('DROP FUNCTION IF EXISTS attendance_punches_immutable()');
        } elseif (in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS attendance_punches_immutable');
        }

        Schema::dropIfExists('attendance_punches');
    }

    private function createImmutabilityTrigger(): void
    {
        $columns = self::IMMUTABLE;
        $message = 'attendance_punches es inmutable: solo se puede completar address';

        switch (DB::getDriverName()) {
            case 'sqlite':
                DB::unprepared(sprintf(
                    'CREATE TRIGGER attendance_punches_immutable BEFORE UPDATE OF %s ON attendance_punches '
                    . "BEGIN SELECT RAISE(ABORT, '%s'); END;",
                    implode(', ', $columns),
                    $message,
                ));
                break;

            case 'mysql':
            case 'mariadb':
                $changed = implode(' OR ', array_map(fn ($c) => "NOT (NEW.`{$c}` <=> OLD.`{$c}`)", $columns));
                DB::unprepared(
                    'CREATE TRIGGER attendance_punches_immutable BEFORE UPDATE ON attendance_punches FOR EACH ROW '
                    . "BEGIN IF {$changed} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END"
                );
                break;

            case 'pgsql':
                $changed = implode(' OR ', array_map(fn ($c) => "NEW.\"{$c}\" IS DISTINCT FROM OLD.\"{$c}\"", $columns));
                DB::unprepared(
                    'CREATE OR REPLACE FUNCTION attendance_punches_immutable() RETURNS trigger AS $$ BEGIN '
                    . "IF {$changed} THEN RAISE EXCEPTION '{$message}'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;"
                );
                DB::unprepared(
                    'CREATE TRIGGER attendance_punches_immutable BEFORE UPDATE ON attendance_punches '
                    . 'FOR EACH ROW EXECUTE FUNCTION attendance_punches_immutable();'
                );
                break;
        }
    }
};
