<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\SensitiveFields;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra acciones sensibles en audit_logs (ver datos bancarios completos,
 * abrir documentos del legajo, exportar planilla, aprobar/rechazar...).
 *
 * Formato de `meta`:
 *  - Cambios estructurados (logChanges / logModelUpdate / saveWithAudit):
 *      {"changes": [{"field": "lunch_paid", "old": false, "new": true}, ...],
 *       "context": {"scope": "tenant"}}
 *  - Eventos simples (log): {"context": {...}} — las filas antiguas guardaban
 *    el contexto plano en meta y siguen leyéndose (AuditLogPresenter).
 *
 * Los campos de SensitiveFields NUNCA se guardan en claro: se enmascaran
 * ("••••••••1234") o quedan como "(cambiado)".
 */
class AuditLogger
{
    /** Columnas técnicas que no aportan al historial de cambios */
    public const IGNORED_FIELDS = ['id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at', 'updated_by'];

    public function log(string $action, ?Model $subject = null, array $meta = []): void
    {
        $this->write($action, $subject, $meta ? ['context' => SensitiveFields::scrub($meta)] : null);
    }

    /**
     * Registra un cambio con valores previos y nuevos. Solo se guardan los
     * campos cuyo valor realmente cambió; los secretos se enmascaran.
     */
    public function logChanges(string $action, Model $subject, array $before, array $after, array $context = []): void
    {
        $changes = self::diff($before, $after);

        $meta = ['changes' => $changes];
        if ($context) {
            $meta['context'] = SensitiveFields::scrub($context);
        }

        $this->write($action, $subject, $meta);
    }

    /**
     * Registra los cambios de un modelo:
     *  - antes de save(): usa getDirty()/getOriginal();
     *  - después de save(): usa getChanges() y $before (snapshot() previo) para los valores anteriores.
     */
    public function logModelUpdate(string $action, Model $model, array $context = [], ?array $before = null): void
    {
        [$old, $new] = self::pendingChanges($model, $before);

        $this->logChanges($action, $model, $old, $new, $context);
    }

    /** Alta de un registro: todos sus campos como "valor nuevo" */
    public function logCreated(string $action, Model $model, array $context = []): void
    {
        $this->logChanges($action, $model, [], self::snapshot($model), $context);
    }

    /** fill + save + bitácora con los cambios, en una línea: reemplaza $model->update($data) */
    public function updateWithAudit(string $action, Model $model, array $data = [], array $context = []): bool
    {
        $model->fill($data);

        return $this->saveWithAudit($action, $model, $context);
    }

    /** save() + bitácora con lo que había pendiente (dirty) en el modelo */
    public function saveWithAudit(string $action, Model $model, array $context = []): bool
    {
        [$old, $new] = self::pendingChanges($model);
        $saved = $model->save();

        $this->logChanges($action, $model, $old, $new, $context);

        return $saved;
    }

    /**
     * Fotografía de los atributos (ya convertidos por los casts) de uno o
     * varios modelos/arreglos; los posteriores pisan a los anteriores.
     */
    public static function snapshot(Model|array|null ...$parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            if ($part instanceof Model) {
                foreach (array_keys($part->getAttributes()) as $key) {
                    $out[$key] = $part->getAttribute($key);
                }
            } elseif (is_array($part)) {
                $out = array_merge($out, $part);
            }
        }

        return $out;
    }

    /** [old, new] de los campos pendientes (o recién guardados) del modelo */
    public static function pendingChanges(Model $model, ?array $before = null): array
    {
        $old = [];
        $new = [];

        $dirty = array_keys($model->getDirty());
        if ($dirty) {
            foreach ($dirty as $key) {
                $old[$key] = $model->exists ? self::originalValue($model, $key) : null;
                $new[$key] = $model->getAttribute($key);
            }

            return [$old, $new];
        }

        foreach (array_keys($model->getChanges()) as $key) {
            $old[$key] = $before[$key] ?? null;
            $new[$key] = $model->getAttribute($key);
        }

        return [$old, $new];
    }

    /** Lista de cambios {field, old, new} entre dos fotografías */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $new) {
            if (! is_string($field) || in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }

            $old = self::normalize($before[$field] ?? null);
            $new = self::normalize($new);

            if (self::same($old, $new)) {
                continue;
            }

            if (SensitiveFields::isSensitive($field)) {
                $old = SensitiveFields::mask($field, $old);
                $new = $new === null ? SensitiveFields::REMOVED : SensitiveFields::mask($field, $new);
            }

            $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
        }

        return $changes;
    }

    private static function originalValue(Model $model, string $key): mixed
    {
        try {
            return $model->getOriginal($key);
        } catch (\Throwable) {
            // p. ej. un cast "encrypted" con una clave de cifrado distinta
            return $model->getRawOriginal($key);
        }
    }

    /** Valores comparables y serializables a JSON */
    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => \Illuminate\Support\Carbon::instance($value)->toIso8601String(),
            $value instanceof BackedEnum         => $value->value,
            $value instanceof \UnitEnum          => $value->name,
            $value instanceof \Illuminate\Support\Collection => $value->all(),
            $value instanceof \ArrayObject       => $value->getArrayCopy(),
            $value instanceof \Stringable        => (string) $value,
            is_object($value)                    => method_exists($value, 'toArray') ? $value->toArray() : (array) $value,
            $value === ''                        => null,
            default                              => $value,
        };
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return $a !== null && $b !== null && (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    private function write(string $action, ?Model $subject, ?array $meta): void
    {
        $request = request();
        $context = app(TenantContext::class);

        AuditLog::create([
            'tenant_id'    => $context->resolved() ? $context->id() : ($subject?->getAttribute('tenant_id')),
            'user_id'      => $request?->user()?->id,
            'action'       => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'ip'           => $request?->ip(),
            'user_agent'   => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'meta'         => $meta ?: null,
        ]);
    }
}
