<?php

/**
 * =============================================================================
 * app/helpers.php — Helpers globales multi-tenant
 *
 * Cargado por Composer ("autoload.files"). Deben vivir en el namespace global:
 * declararlos dentro de TenantServiceProvider los dejaba como
 * App\Providers\current_tenant() y no se encontraban desde los controladores.
 * =============================================================================
 */

use App\Modules\Core\Services\TenantContext;

if (! function_exists('current_tenant')) {
    /**
     * Retorna el tenant activo en la request actual.
     */
    function current_tenant(): \App\Modules\Core\Models\Tenant
    {
        return app(TenantContext::class)->get();
    }
}

if (! function_exists('current_tenant_id')) {
    /**
     * Retorna el ID del tenant activo.
     * Útil para queries: User::where('tenant_id', current_tenant_id())
     */
    function current_tenant_id(): int
    {
        return app(TenantContext::class)->id();
    }
}

if (! function_exists('current_tenant_id_or_null')) {
    /**
     * ID del tenant activo o null (Super Admin en la vista global del panel).
     * Úsalo con el scope forTenant(): X::forTenant(current_tenant_id_or_null()).
     */
    function current_tenant_id_or_null(): ?int
    {
        $ctx = app(TenantContext::class);
        return $ctx->resolved() ? $ctx->id() : null;
    }
}

if (! function_exists('tenant_timezone')) {
    /**
     * Retorna la zona horaria del tenant activo.
     * Útil para Carbon: now(tenant_timezone())
     */
    function tenant_timezone(): string
    {
        $ctx = app(TenantContext::class);
        return $ctx->resolved() ? $ctx->get()->timezone : config('app.timezone');
    }
}

if (! function_exists('attendance_photo_url')) {
    /**
     * URL temporal (firmada) de la selfie de entrada ('in') o salida ('out').
     */
    function attendance_photo_url(\App\Modules\Attendance\Models\Attendance $attendance, string $moment = 'in'): ?string
    {
        return app(\App\Modules\Attendance\Services\AttendanceEvidence::class)->photoUrl($attendance, $moment);
    }
}

if (! function_exists('attendance_photo_thumb_url')) {
    /**
     * Miniatura de 160 px (firmada) de la selfie de entrada ('in') o salida ('out'), para listados.
     */
    function attendance_photo_thumb_url(\App\Modules\Attendance\Models\Attendance $attendance, string $moment = 'in'): ?string
    {
        return app(\App\Modules\Attendance\Services\AttendanceEvidence::class)->thumbUrl($attendance, $moment);
    }
}

// =============================================================================
// Presentación de datos (App\Modules\Core\Support\Labels): nada de claves ni enums crudos en la UI
// =============================================================================

if (! function_exists('label')) {
    /**
     * Etiqueta en español de un valor de enum: label('leave_status', 'approved') → "Aprobada".
     * Conjuntos en App\Modules\Core\Support\Labels::ENUMS (attendance_status, leave_status, leave_type,
     * document_status, document_type, payslip_status, user_role, user_status, tenant_status,
     * tenant_plan, shift_type, branch_status, notification_type, holiday_type, uniform_item...).
     */
    function label(string $set, mixed $value): string
    {
        return \App\Modules\Core\Support\Labels::enum($set, $value);
    }
}

if (! function_exists('field_label')) {
    /** Nombre en español de una columna: field_label('default_grace_minutes') → "Tolerancia por defecto (min)" */
    function field_label(string $field): string
    {
        return \App\Modules\Core\Support\Labels::field($field);
    }
}

if (! function_exists('field_value')) {
    /**
     * Valor formateado según la columna (Sí/No, unidades, fechas en la zona de la empresa,
     * muestra de color, secretos enmascarados). Devuelve HtmlString solo para colores.
     */
    function field_value(string $field, mixed $value, bool $inherits = false): string|\Illuminate\Support\HtmlString
    {
        return \App\Modules\Core\Support\Labels::value($field, $value, null, $inherits);
    }
}

if (! function_exists('masked')) {
    /** Secreto para mostrar: "sk_live_abcdef1234" → "••••••••1234" */
    function masked(?string $secret): ?string
    {
        return \App\Modules\Core\Support\SensitiveFields::maskString($secret);
    }
}

if (! function_exists('hora')) {
    /**
     * Hora para mostrar en formato de 12 horas ("08:00 am", "05:30 pm").
     * Acepta Carbon/DateTime o un texto "HH:mm[:ss]" (columnas TIME de turnos).
     * En la base de datos las horas siguen en 24 h; esto es solo presentación.
     */
    function hora(\DateTimeInterface|string|null $value, bool $seconds = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $format = $seconds ? 'h:i:s a' : 'h:i a';
        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }
        $parsed = \DateTime::createFromFormat('!H:i:s', strlen($value) === 5 ? $value . ':00' : substr($value, 0, 8));

        return $parsed ? $parsed->format($format) : $value;
    }
}

if (! function_exists('formato_duracion')) {
    /**
     * Formatea una duración en horas, minutos y segundos respetando espacios estándar ("3 h 51 min 14 s").
     */
    function formato_duracion(int|float $totalSeconds, bool $showSeconds = true): string
    {
        $totalSeconds = (int) round($totalSeconds);
        if ($totalSeconds <= 0) {
            return '0 min';
        }

        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours} h";
        }
        if ($minutes > 0 || ($hours > 0 && $seconds > 0)) {
            $parts[] = "{$minutes} min";
        } elseif ($hours === 0 && $seconds === 0) {
            $parts[] = "{$minutes} min";
        }
        if ($showSeconds && $seconds > 0) {
            $parts[] = "{$seconds} s";
        }

        return implode(' ', $parts);
    }
}

if (! function_exists('formato_tardanza')) {
    /**
     * Retorna la tardanza de una asistencia desglosada en horas, minutos y segundos ("3 h 51 min 14 s tarde").
     * Si no hay turno o segundos, calcula a partir de late_minutes ("3 h 51 min tarde").
     */
    function formato_tardanza(?\App\Modules\Attendance\Models\Attendance $attendance, bool $showSeconds = true): ?string
    {
        if (! $attendance || ! $attendance->late_minutes || $attendance->late_minutes <= 0) {
            return null;
        }

        $lateSeconds = 0;

        if ($attendance->clock_in_at && $attendance->shift && ! empty($attendance->shift->start_time)) {
            $dateStr = $attendance->work_date instanceof \DateTimeInterface
                ? $attendance->work_date->format('Y-m-d')
                : substr((string) $attendance->work_date, 0, 10);
            $tz = tenant_timezone();
            try {
                $scheduled = \Carbon\Carbon::parse("{$dateStr} {$attendance->shift->start_time}", $tz);
                $clockIn = \Carbon\Carbon::parse($attendance->clock_in_at, $tz);

                if ($clockIn->greaterThan($scheduled)) {
                    $lateSeconds = abs($scheduled->diffInSeconds($clockIn));
                }
            } catch (\Throwable) {
                $lateSeconds = 0;
            }
        }

        if ($lateSeconds <= 0 && $showSeconds && $attendance->clock_in_at) {
            try {
                $clockIn = \Carbon\Carbon::parse($attendance->clock_in_at);
                $lateSeconds = ($attendance->late_minutes * 60) + (int) $clockIn->second;
            } catch (\Throwable) {
                $lateSeconds = 0;
            }
        }

        if ($lateSeconds <= 0) {
            $lateSeconds = (int) $attendance->late_minutes * 60;
        }

        return formato_duracion($lateSeconds, $showSeconds) . ' tarde';
    }
}

if (! function_exists('formato_horas_minutos')) {
    /**
     * Formatea horas decimales en horas y minutos respetando espacios ("1 h 30 min", "8 h", "45 min").
     */
    function formato_horas_minutos(int|float $hours): string
    {
        $totalMinutes = (int) round((float) $hours * 60);
        if ($totalMinutes <= 0) {
            return '0 h';
        }
        $h = intdiv($totalMinutes, 60);
        $m = $totalMinutes % 60;

        if ($h > 0 && $m > 0) {
            return "{$h} h {$m} min";
        }
        if ($h > 0) {
            return "{$h} h";
        }

        return "{$m} min";
    }
}

