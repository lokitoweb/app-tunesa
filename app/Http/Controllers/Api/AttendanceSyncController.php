<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Concerns\MarksWithTenantSettings;
use App\Models\AttendancePunch;
use App\Models\TenantSetting;
use App\Services\Attendance\PunchData;
use App\Services\AttendanceService;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/attendances/sync-batch (alias /api/attendances/sync-batch)
 *
 * Sincroniza en un solo envío las marcaciones que la app guardó sin conexión
 * (máx. 50). Multipart: punches[i][type], punches[i][client_uuid] (obligatorio),
 * latitude, longitude, accuracy_meters, device_time, offline_recorded_at,
 * is_mocked, face_detected, photo, device_id, device_os, app_version; y a
 * nivel raíz device_sent_at opcional (reloj del teléfono al enviar).
 *
 * Se procesan en orden cronológico (offline_recorded_at, si no device_time;
 * empate: entrada < inicio descanso < fin descanso < salida), cada una en su
 * propia transacción: un rechazo nunca aborta el lote. Las mismas reglas que
 * los endpoints individuales (offline_mode_enabled, offline_max_hours, GPS
 * simulado, precisión, rostro, geocerca...) se aplican a cada elemento.
 *
 * Respuesta 200: results[] en el mismo orden que punches[], con status
 * created | duplicate | rejected y meta con los totales.
 */
class AttendanceSyncController extends Controller
{
    public const MAX_ITEMS = 50;

    public function __construct(private readonly AttendanceService $attendance) {}

    public function syncBatch(Request $request): JsonResponse
    {
        $request->validate([
            'punches'        => ['required', 'array', 'min:1', 'max:' . self::MAX_ITEMS],
            'device_sent_at' => ['sometimes', 'nullable', 'date'],
        ], [
            'punches.required' => 'Envía al menos una marcación en punches[].',
            'punches.max'      => 'Máximo ' . self::MAX_ITEMS . ' marcaciones por envío.',
        ]);

        $settings = TenantSetting::forTenant(app(TenantContext::class)->getOrNull());
        $items    = (array) $request->input('punches', []);
        $sentAt   = $request->input('device_sent_at');
        $employee = $request->user();

        $results = [];
        foreach ($this->chronological($items) as $key) {
            $results[$key] = $this->processItem($request, $key, $items[$key], $settings, $employee, $sentAt);
        }

        // Respuesta en el mismo orden que punches[]
        $ordered = array_values(array_map(fn ($key) => $results[$key], array_keys($items)));
        $count   = fn (string $status) => count(array_filter($ordered, fn ($r) => $r['status'] === $status));

        return response()->json([
            'success' => true,
            'data'    => ['results' => $ordered],
            'meta'    => [
                'created'    => $count('created'),
                'duplicates' => $count('duplicate'),
                'rejected'   => $count('rejected'),
            ],
        ]);
    }

    /** Claves de punches[] ordenadas por hora real de la marcación */
    private function chronological(array $items): array
    {
        $now  = CarbonImmutable::now('UTC')->getTimestamp();
        $rows = [];
        $pos  = 0;

        foreach ($items as $key => $item) {
            $item = is_array($item) ? $item : [];
            $time = $now;
            foreach (['offline_recorded_at', 'device_time'] as $field) {
                if (filled($item[$field] ?? null) && is_string($item[$field])) {
                    try {
                        $time = CarbonImmutable::parse($item[$field])->getTimestamp();
                        break;
                    } catch (\Throwable) {
                        // Hora inválida: lo rechaza la validación del elemento
                    }
                }
            }

            $rows[] = [$key, $time, AttendanceService::typeOrder((string) ($item['type'] ?? '')), $pos++];
        }

        usort($rows, fn ($a, $b) => [$a[1], $a[2], $a[3]] <=> [$b[1], $b[2], $b[3]]);

        return array_column($rows, 0);
    }

    private function processItem(Request $request, int|string $key, mixed $item, TenantSetting $settings, $employee, ?string $sentAt): array
    {
        $item       = is_array($item) ? $item : [];
        $clientUuid = is_string($item['client_uuid'] ?? null) ? strtolower($item['client_uuid']) : null;
        $photo      = $request->file("punches.{$key}.photo");

        try {
            $type      = (string) ($item['type'] ?? '');
            $validator = Validator::make(
                $item + ($photo instanceof UploadedFile ? ['photo' => $photo] : []),
                [
                    'type'        => ['required', Rule::in(AttendancePunch::TYPES)],
                    'client_uuid' => ['required', 'uuid'],
                    'photo'       => MarksWithTenantSettings::photoRulesFor(
                        in_array($type, ['clock_in', 'clock_out'], true) && $settings->selfie_required
                    ),
                ] + MarksWithTenantSettings::integrityRules(),
                [
                    'photo.required'       => 'La foto selfie es obligatoria para esta marcación.',
                    'client_uuid.required' => 'Cada marcación necesita su client_uuid.',
                ] + MarksWithTenantSettings::integrityMessages(),
            );

            $validated = $validator->validate();

            $result = $this->attendance->punch($employee, PunchData::fromInput(
                $type,
                $validated,
                $photo instanceof UploadedFile ? $photo : null,
                $request->ip(),
                $request->userAgent(),
                $sentAt,
            ));

            return [
                'client_uuid'   => $clientUuid,
                'status'        => $result->duplicate ? 'duplicate' : 'created',
                'code'          => null,
                'message'       => $result->duplicate ? 'Esta marcación ya estaba registrada.' : AttendanceController::successMessage($result),
                'attendance_id' => $result->attendance->id,
                'punch_id'      => $result->punch->id,
            ];
        } catch (ValidationException $e) {
            return $this->rejected($clientUuid, 'VALIDATION_ERROR', collect($e->errors())->flatten()->first() ?? 'Datos inválidos.');
        } catch (AttendanceException $e) {
            return $this->rejected($clientUuid, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->rejected($clientUuid, 'SERVER_ERROR', 'No se pudo registrar la marcación. Intenta de nuevo en unos minutos.');
        }
    }

    private function rejected(?string $clientUuid, string $code, string $message): array
    {
        return [
            'client_uuid'   => $clientUuid,
            'status'        => 'rejected',
            'code'          => $code,
            'message'       => $message,
            'attendance_id' => null,
            'punch_id'      => null,
        ];
    }
}
