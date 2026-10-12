<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 · selfie segura:
 *  - photo_sha256: huella del archivo guardado (evidencia). Pasa a ser inmutable:
 *    se rehace el trigger de attendance_punches con esta columna.
 *  - photo_purged_at: cuándo attendance:purge-photos borró el archivo por
 *    retención. Es la única columna nueva que se puede completar después
 *    (photo_path y la huella quedan como constancia de que hubo foto).
 */
return new class extends Migration
{
    /** Columnas del trigger original (create_attendance_punches_table) */
    private const BASE = [
        'tenant_id', 'attendance_id', 'user_id', 'branch_id', 'type', 'client_uuid',
        'latitude', 'longitude', 'accuracy_meters', 'distance_meters', 'within_geofence',
        'device_time', 'server_time', 'time_drift_seconds', 'offline', 'offline_recorded_at',
        'photo_path', 'face_detected', 'mock_location_detected', 'suspicious', 'suspicion_reasons',
        'device_id', 'device_os', 'app_version', 'ip', 'created_at',
    ];

    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->char('photo_sha256', 64)->nullable()->after('photo_path');
            $table->timestamp('photo_purged_at')->nullable()->after('photo_sha256');
        });

        $this->recreateTrigger([...self::BASE, 'photo_sha256']);
    }

    public function down(): void
    {
        $this->recreateTrigger(self::BASE);

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropColumn(['photo_sha256', 'photo_purged_at']);
        });
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
                // El trigger ya llama a la función: basta con reemplazarla
                $changed = implode(' OR ', array_map(fn ($c) => "NEW.\"{$c}\" IS DISTINCT FROM OLD.\"{$c}\"", $columns));
                DB::unprepared(
                    'CREATE OR REPLACE FUNCTION attendance_punches_immutable() RETURNS trigger AS $$ BEGIN '
                    . "IF {$changed} THEN RAISE EXCEPTION '{$message}'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql;"
                );
                break;
        }
    }
};
