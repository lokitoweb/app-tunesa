<?php

namespace App\Services;

use App\Jobs\SendTenantWebhook;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Integrations\WebhookSender;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Integraciones de "Marca y app" (pestaña Integraciones):
 *
 *  - webhook_url: attendance.clock_in, attendance.break_start,
 *    attendance.break_end, attendance.clock_out y leave_request.status_changed.
 *  - notification_endpoint: notification.created por cada aviso in-app
 *    (canal database) que recibe un usuario de la empresa.
 *
 * Cuerpo: {"event", "occurred_at", "tenant": {"slug"}, "data": {...}}, firmado
 * con webhook_secret (ver WebhookSender). Se encola un job por evento y cada
 * envío queda en webhook_deliveries (historial del panel); un
 * fallo nunca interrumpe la acción que lo originó (marcar, aprobar...).
 */
class IntegrationDispatcher
{
    /** Columna datetime → momento del evento en el Attendance */
    private const ATTENDANCE_EVENTS = [
        'clock_in_at'    => 'attendance.clock_in',
        'break_start_at' => 'attendance.break_start',
        'break_end_at'   => 'attendance.break_end',
        'clock_out_at'   => 'attendance.clock_out',
    ];

    public function attendanceSaved(Attendance $attendance, bool $created = false): void
    {
        foreach (self::ATTENDANCE_EVENTS as $column => $event) {
            $happened = $attendance->{$column} !== null
                && ($created || ($attendance->wasChanged($column) && $attendance->getOriginal($column) === null));

            if ($happened) {
                $this->send($attendance->tenant_id, SendTenantWebhook::TARGET_WEBHOOK, $event, fn (Tenant $t) => $this->attendanceData($attendance, $t));
            }
        }
    }

    public function leaveUpdated(LeaveRequest $leave): void
    {
        if (! $leave->wasChanged('status')) {
            return;
        }

        $this->send($leave->tenant_id, SendTenantWebhook::TARGET_WEBHOOK, 'leave_request.status_changed', fn () => [
            'id'              => $leave->id,
            'user_id'         => $leave->user_id,
            'type'            => $leave->type,
            'start_date'      => $leave->start_date?->toDateString(),
            'end_date'        => $leave->end_date?->toDateString(),
            'days'            => $leave->days !== null ? (float) $leave->days : null,
            'status'          => $leave->status,
            'previous_status' => $leave->getOriginal('status'),
            'reviewed_by'     => $leave->reviewed_by,
            'review_notes'    => $leave->review_notes,
        ]);
    }

    public function notificationCreated(object $notifiable, DatabaseNotification $notification): void
    {
        $tenantId = $notifiable->tenant_id ?? null;
        if (! $tenantId) {
            return;
        }

        $data = $notification->data ?? [];

        $this->send($tenantId, SendTenantWebhook::TARGET_NOTIFICATION, 'notification.created', fn () => [
            'id'      => $notification->id,
            'type'    => $data['type'] ?? $notification->type,
            'user_id' => $notifiable->getKey(),
            'title'   => $data['title'] ?? null,
            'body'    => $data['body'] ?? null,
            'data'    => $data['data'] ?? [],
        ]);
    }

    /**
     * Arma el cuerpo y encola el envío si la empresa configuró la integración.
     *
     * @param  callable(Tenant): array  $data
     */
    private function send(?int $tenantId, string $target, string $event, callable $data): void
    {
        if (! $tenantId) {
            return;
        }

        try {
            $tenant = Tenant::find($tenantId);
            $row    = $tenant ? TenantSetting::rowFor($tenant) : null;

            // webhook_url y notification_endpoint son propios de cada empresa (no se heredan)
            if (! $row || blank($row->{$target})) {
                return;
            }

            $body = json_encode([
                'event'       => $event,
                'occurred_at' => Carbon::now($tenant->timezone)->toIso8601String(),
                'tenant'      => ['slug' => $tenant->slug],
                'data'        => $data($tenant),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $job = new SendTenantWebhook($tenant->id, $target, $event, $body);
            $url = $row->{$target};

            // Después del commit (la marcación se guarda en una transacción). Primero la fila
            // "pending" en webhook_deliveries, para que el historial la muestre aunque no haya
            // worker de la cola. Con la cola "sync" el job corre dentro de este callback: su
            // fallo solo va al log y no tumba la marcación.
            DB::afterCommit(function () use ($job, $tenant, $target, $event, $url, $body, $tenantId) {
                try {
                    app(WebhookSender::class)->open($tenant->id, $target, $event, $url, $body, $job->deliveryUuid);
                    dispatch($job);
                } catch (\Throwable $e) {
                    Log::warning("No se pudo enviar el evento {$event} a la integración: " . $e->getMessage(), [
                        'tenant_id' => $tenantId,
                        'target'    => $target,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Con la cola "sync" el envío corre aquí mismo: su fallo no debe tumbar la marcación
            Log::warning("No se pudo enviar el evento {$event} a la integración: " . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'target'    => $target,
            ]);
        }
    }

    private function attendanceData(Attendance $a, Tenant $tenant): array
    {
        $time = fn (?CarbonInterface $t) => $t
            // Las columnas guardan la hora de pared de la empresa: se publica con su zona
            ? Carbon::createFromFormat('Y-m-d H:i:s', $t->format('Y-m-d H:i:s'), $tenant->timezone)->toIso8601String()
            : null;

        return [
            'id'                        => $a->id,
            'user_id'                   => $a->user_id,
            'employee_code'             => $a->user?->employee_code,
            'branch_id'                 => $a->branch_id,
            'work_date'                 => $a->work_date?->toDateString(),
            'clock_in_at'               => $time($a->clock_in_at),
            'break_start_at'            => $time($a->break_start_at),
            'break_end_at'              => $time($a->break_end_at),
            'clock_out_at'              => $time($a->clock_out_at),
            'status'                    => $a->status,
            'late_minutes'              => $a->late_minutes,
            'break_minutes'             => $a->break_minutes,
            'worked_hours'              => $a->worked_hours !== null ? (float) $a->worked_hours : null,
            'clock_in_within_geofence'  => $a->clock_in_within_geofence,
            'clock_out_within_geofence' => $a->clock_out_within_geofence,
        ];
    }
}
