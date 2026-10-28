<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de contingencia de marcación: RR. HH. lo activa (alcance, motivo y vigencia) y, mientras
 * rige, el colaborador marca desde Mi espacio con la hora del servidor y sin GPS.
 *
 *  - attendance_punches: latitude/longitude pasan a admitir vacío (una marca de contingencia no
 *    tiene ubicación; nunca se inventa) y contingency_id dice bajo qué plan se marcó. La bitácora
 *    sigue inmutable: se rehace el trigger con la columna nueva. contingency_id va sin llave
 *    foránea a propósito: un ON DELETE tocaría filas inmutables y los planes nunca se borran.
 *  - attendances: *_within_geofence admite vacío = "sin ubicación" (antes era siempre sí/no).
 */
return new class extends Migration
{
    /** Columnas del trigger (create_attendance_punches_table + photo_sha256) */
    private const IMMUTABLE = [
        'tenant_id', 'attendance_id', 'user_id', 'branch_id', 'type', 'client_uuid',
        'latitude', 'longitude', 'accuracy_meters', 'distance_meters', 'within_geofence',
        'device_time', 'server_time', 'time_drift_seconds', 'offline', 'offline_recorded_at',
        'photo_path', 'face_detected', 'mock_location_detected', 'suspicious', 'suspicion_reasons',
        'device_id', 'device_os', 'app_version', 'ip', 'created_at', 'photo_sha256',
    ];

    public function up(): void
    {
        Schema::create('attendance_contingencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 20);          // all | branch | department | users
            $table->json('scope_ids')->nullable();
            $table->string('reason', 200);
            $table->dateTime('starts_at')->comment('UTC');
            $table->dateTime('ends_at')->comment('UTC');
            $table->dateTime('ended_at')->nullable()->comment('UTC, si se desactivó antes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'ends_at']);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable()->change();
            $table->decimal('longitude', 11, 8)->nullable()->change();
            $table->unsignedBigInteger('contingency_id')->nullable()->index();
        });
        $this->recreateTrigger([...self::IMMUTABLE, 'contingency_id']);

        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('clock_in_within_geofence')->nullable()->default(false)->change();
            $table->boolean('clock_out_within_geofence')->nullable()->default(false)->change();
        });
    }

    public function down(): void
    {
        $this->recreateTrigger(self::IMMUTABLE);
        Schema::table('attendance_punches', fn (Blueprint $table) => $table->dropColumn('contingency_id'));
        Schema::dropIfExists('attendance_contingencies');
    }

    private function recreateTrigger(array $columns): void
    {
        $message = 'attendance_punches es inmutable: solo se puede completar address';

        switch (DB::getDriverName()) {
            case 'sqlite':
                DB::unprepared('DROP TRIGGER IF EXISTS attendance_punches_immutable');
                DB::unprepared(sprintf(
                    'CREATE TRIGGER attendance_punches_immutable BEFORE UPDATE OF %s ON attendance_punches '
                    . "BEGIN SELECT RAISE(ABORT, '%s'); END;",
                    implode(', ', $columns),
                    $message,
                ));
                break;

            case 'mysql':
            case 'mariadb':
                DB::unprepared('DROP TRIGGER IF EXISTS attendance_punches_immutable');
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
                break;
        }
    }
};
