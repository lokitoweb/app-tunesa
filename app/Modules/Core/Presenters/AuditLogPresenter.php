<?php

namespace App\Modules\Core\Presenters;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Labels;
use App\Modules\Core\Support\SensitiveFields;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Presentación de un registro de la bitácora para personas: etiquetas en
 * español, valores formateados, secretos enmascarados y nada de JSON crudo.
 *
 * Lee los dos formatos de `meta`:
 *  - nuevo: {"changes": [{field, old, new}], "context": {...}}
 *  - antiguo: contexto plano {"period": "2026-09", "rows": 12}
 */
class AuditLogPresenter
{
    /** Roles que ven la IP completa */
    public const FULL_IP_ROLES = ['super_admin', 'company_admin'];

    private ?array $changesCache = null;

    /** Columnas que apuntan a otro registro: se muestra su nombre */
    private const REFERENCES = [
        'supervisor_id'       => [User::class, 'full_name'],
        'user_id'             => [User::class, 'full_name'],
        'employee_id'         => [User::class, 'full_name'],
        'reviewed_by'         => [User::class, 'full_name'],
        'uploaded_by'         => [User::class, 'full_name'],
        'payment_verified_by' => [User::class, 'full_name'],
        'assigned_by'         => [User::class, 'full_name'],
        'delivered_by'        => [User::class, 'full_name'],
        'branch_id'           => [\App\Modules\Core\Models\Branch::class, 'name'],
        'scope_branch_id'     => [\App\Modules\Core\Models\Branch::class, 'name'],
        'zone_id'             => [\App\Modules\Core\Models\Zone::class, 'name'],
        'scope_zone_id'       => [\App\Modules\Core\Models\Zone::class, 'name'],
        'department_id'       => [\App\Modules\Core\Models\Department::class, 'name'],
        'position_id'         => [\App\Modules\Core\Models\Position::class, 'name'],
        'shift_id'            => [\App\Modules\HR\Models\Shift::class, 'name'],
        'tenant_id'           => [\App\Modules\Core\Models\Tenant::class, 'company_name'],
    ];

    public function __construct(
        public readonly AuditLog $log,
        private readonly ?User $viewer = null,
    ) {}

    public static function make(AuditLog $log, ?User $viewer = null): self
    {
        return new self($log, $viewer);
    }

    public function badge(): array
    {
        return Labels::actionBadge($this->log->action);
    }

    public function actionLabel(): string
    {
        return Labels::action($this->log->action);
    }

    public function actorName(): string
    {
        $user = $this->log->user;

        return $user ? trim("{$user->first_name} {$user->last_name}") : 'El sistema';
    }

    public function actorRole(): ?string
    {
        return $this->log->user ? Labels::enum('user_role', $this->log->user->role) : null;
    }

    /** "Sucursal Sede Centro", "Marca y app" (sin números internos) */
    public function subjectLabel(): string
    {
        if (! $this->log->subject_type) {
            return '—';
        }
        $context = $this->context();
        $name    = $context['name'] ?? $context['branch_name'] ?? $context['title'] ?? null;

        return Labels::subject($this->log->subject_type) . ($name && is_scalar($name) ? ' · ' . $name : '');
    }

    public function isStructured(): bool
    {
        $meta = $this->log->meta ?? [];

        return array_key_exists('changes', $meta) && is_array($meta['changes']);
    }

    public function hasChanges(): bool
    {
        return $this->changes() !== [];
    }

    /** Contexto (formato nuevo) o el meta plano de filas antiguas, sin secretos */
    public function context(): array
    {
        $meta = $this->log->meta ?? [];
        if (array_key_exists('changes', $meta) || (array_key_exists('context', $meta) && is_array($meta['context']) && count($meta) === 1)) {
            $context = is_array($meta['context'] ?? null) ? $meta['context'] : [];
        } else {
            $context = $meta;
        }

        return SensitiveFields::scrub($context);
    }

    /**
     * Filas del cuadro de cambios:
     *  [['field' => 'lunch_paid', 'label' => 'Almuerzo pagado', 'old' => 'No', 'new' => 'Sí'], ...]
     */
    public function changes(): array
    {
        if ($this->changesCache !== null) {
            return $this->changesCache;
        }
        if (! $this->isStructured()) {
            return $this->changesCache = [];
        }

        $rows = [];
        foreach ($this->log->meta['changes'] as $change) {
            $field = (string) ($change['field'] ?? '');
            if ($field === '' || (Labels::FIELD_TYPES[$field] ?? null) === 'hidden') {
                continue;
            }
            $rows[] = [
                'field' => $field,
                'label' => Labels::field($field, $this->log->subject_type),
                'old'   => $this->formatValue($field, $change['old'] ?? null),
                'new'   => $this->formatValue($field, $change['new'] ?? null),
            ];
        }

        return $this->changesCache = $rows;
    }

    /** [etiqueta => valor] del contexto, para filas sin cambios estructurados */
    public function contextPairs(): array
    {
        $pairs = [];
        foreach ($this->context() as $key => $value) {
            if (! is_string($key) || (Labels::FIELD_TYPES[$key] ?? null) === 'hidden' || $key === 'name' && $this->log->subject_type) {
                continue;
            }
            if (is_array($value) && $value === []) {
                continue;
            }
            $formatted = $this->formatValue($key, $value);
            $pairs[Labels::field($key, $this->log->subject_type)] = $formatted instanceof HtmlString ? $formatted : Str::limit((string) $formatted, 160);
        }

        return $pairs;
    }

    /**
     * "Carlos Ramírez cambió 3 campos de Marca y app: Tolerancia por defecto, Almuerzo pagado y 1 más."
     * Para eventos sin cambios devuelve "Carlos Ramírez: Documento abierto."
     */
    public function summary(): string
    {
        $actor = $this->actorName();

        if (! $this->isStructured()) {
            return "{$actor}: " . Str::lcfirst($this->actionLabel()) . '.';
        }

        $changes = $this->changes();
        $area    = Labels::area($this->log->action);
        $count   = count($changes);

        if ($count === 0) {
            return "{$actor} guardó {$area} sin cambios.";
        }

        $names = array_map(fn ($c) => self::shortLabel($c['label']), $changes);
        $list  = Labels::join($names, 2);

        // "de el turno" → "del turno"
        $of = str_starts_with($area, 'el ') ? 'del ' . substr($area, 3) : "de {$area}";

        return $count === 1
            ? "{$actor} cambió 1 campo {$of}: {$list}."
            : "{$actor} cambió {$count} campos {$of}: {$list}.";
    }

    /** IP completa para Super Admin y Administrador; parcial para el resto */
    public function ip(): string
    {
        $ip = $this->log->ip;
        if (blank($ip)) {
            return '—';
        }

        return $this->viewer && in_array($this->viewer->role, self::FULL_IP_ROLES, true) ? $ip : (string) SensitiveFields::maskIp($ip);
    }

    public function canSeeIp(): bool
    {
        return $this->viewer !== null && in_array($this->viewer->role, self::FULL_IP_ROLES, true);
    }

    /** "Chrome en Android" */
    public function device(): string
    {
        return Labels::userAgent($this->log->user_agent);
    }

    public function date(): string
    {
        return $this->log->created_at ? Labels::datetime($this->log->created_at) : '—';
    }

    /**
     * Fila lista para la pantalla Vue (Core/Pages/Audit/Index). Los valores son
     * {text} o {html}: html solo lo genera Labels (muestra de color), nunca el usuario.
     */
    public function toArray(): array
    {
        $cell = fn (string|HtmlString $v) => $v instanceof HtmlString ? ['html' => (string) $v] : ['text' => $v];

        return [
            'id'            => $this->log->id,
            'tenant_id'     => $this->log->tenant_id,
            'date'          => $this->date(),
            'actor_name'    => $this->actorName(),
            'actor_role'    => $this->actorRole(),
            'badge'         => $this->badge(),
            'action_label'  => $this->actionLabel(),
            'subject_label' => $this->subjectLabel(),
            'structured'    => $this->isStructured(),
            'summary'       => $this->isStructured() ? $this->summary() : null,
            'changes'       => array_map(fn (array $c) => ['label' => $c['label'], 'old' => $cell($c['old']), 'new' => $cell($c['new'])], $this->changes()),
            'pairs'         => collect($this->contextPairs())->map(fn ($v, $label) => ['label' => $label] + $cell($v))->values()->all(),
            'device'        => $this->device(),
            'ip'            => $this->ip(),
            'full_ip'       => $this->canSeeIp(),
        ];
    }

    public function formatValue(string $field, mixed $value): string|HtmlString
    {
        if (is_string($value) && SensitiveFields::isMasked($value)) {
            return $value;
        }
        if (is_numeric($value) && isset(self::REFERENCES[$field])) {
            return $this->referenceName($field, (int) $value);
        }

        return Labels::value($field, $value, $this->log->subject_type, $this->inherits($field));
    }

    /** Nombre del registro vinculado (colaborador, sucursal, turno), nunca su número interno */
    private function referenceName(string $field, int $id): string
    {
        [$class, $attribute] = self::REFERENCES[$field];

        $query = $class::query()->withoutGlobalScopes();
        if ($this->log->tenant_id && $class !== \App\Modules\Core\Models\Tenant::class) {
            // Nunca se resuelven nombres de otra empresa
            $query->where(fn ($q) => $q->where('tenant_id', $this->log->tenant_id)->orWhereNull('tenant_id'));
        }
        $model = $query->find($id);

        return $model ? (string) $model->{$attribute} : 'Registro eliminado';
    }

    /** En Marca y app de una empresa, vacío = toma el valor de la plataforma */
    private function inherits(string $field): bool
    {
        if ($this->log->subject_type !== 'TenantSetting' || ($this->context()['scope'] ?? 'tenant') === 'platform') {
            return false;
        }

        return ! in_array($field, TenantSetting::NOT_INHERITED, true) && ! in_array($field, TenantSetting::SECRET_FIELDS, true);
    }

    /** "Tolerancia por defecto (min)" → "Tolerancia por defecto" */
    private static function shortLabel(string $label): string
    {
        return trim(preg_replace('/\s*\([^)]*\)$/', '', $label));
    }
}
