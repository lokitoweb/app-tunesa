<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Services\ExpiryAlerts;
use App\Modules\HR\Services\ExpiryWatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** RR. HH. › Vencimientos: tablero y a quién se avisa (correo y Telegram) */
class ExpirationWebController extends Controller
{
    public function __construct(private readonly ExpiryWatch $watch, private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenant();
        $days = in_array($request->integer('days'), [30, 60, 90, 180], true) ? $request->integer('days') : 60;
        $kind = array_key_exists((string) $request->query('kind'), ExpiryWatch::KINDS) ? $request->query('kind') : null;
        $items = $this->watch->items($tenant, $days)->when($kind, fn ($c) => $c->where('kind', $kind)->values());
        $canManage = Auth::user()->hasPermission('expirations.manage');
        $settings = TenantSetting::editableRowFor($tenant);
        $config = TenantSetting::forTenant($tenant)->expiryAlerts();

        return Inertia::render('HR/Pages/Expirations/Index', [
            'items'    => $items,
            'counts'   => [
                'expired' => $items->where('days_left', '<', 0)->count(),
                'week'    => $items->whereBetween('days_left', [0, 7])->count(),
                'month'   => $items->whereBetween('days_left', [8, 30])->count(),
            ],
            'kinds'    => ExpiryWatch::KINDS,
            'filters'  => ['days' => $days, 'kind' => $kind],
            'settings' => $canManage ? [
                'emails' => $settings->expiry_alert_emails, 'chats' => $settings->expiry_alert_telegram_chats,
                'days' => $settings->expiry_alert_days ?: '30,15,7,1,0', 'telegram_ready' => (bool) $config['telegram_token'],
            ] : null,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'emails' => ['nullable', 'string', 'max:1000'],
            'chats'  => ['nullable', 'string', 'max:500'],
            'days'   => ['required', 'string', 'max:50', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
        ], ['days.regex' => 'Escribe los días separados por coma, por ejemplo 30,15,7,1,0.'], ['emails' => 'los correos', 'chats' => 'los chats de Telegram', 'days' => 'los días']);

        $data += ['emails' => null, 'chats' => null];
        $split = fn (?string $v) => array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $v)), fn ($x) => $x !== ''));
        $bad = array_filter($split($data['emails']), fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($bad) {
            throw \Illuminate\Validation\ValidationException::withMessages(['emails' => 'Correo no válido: ' . implode(', ', $bad)]);
        }
        $badChat = array_filter($split($data['chats']), fn ($c) => ! preg_match('/^-?\d{3,20}$|^@\w{5,32}$/', $c));
        if ($badChat) {
            throw \Illuminate\Validation\ValidationException::withMessages(['chats' => 'Chat de Telegram no válido: ' . implode(', ', $badChat) . ' (es un número, o @canal).']);
        }

        TenantSetting::editableRowFor($tenant)->fill([
            'expiry_alert_emails' => implode(', ', $split($data['emails'])) ?: null,
            'expiry_alert_telegram_chats' => implode(', ', $split($data['chats'])) ?: null,
            'expiry_alert_days' => implode(',', array_unique(array_map('intval', $split($data['days'])))),
            'updated_by' => Auth::id(),
        ])->save();
        $this->audit->log('expirations.settings_updated', null, ['emails' => count($split($data['emails'])), 'chats' => count($split($data['chats'])), 'days' => $data['days']]);

        return back()->with('success', 'Alertas de vencimientos guardadas.');
    }

    public function test(ExpiryAlerts $alerts): RedirectResponse
    {
        $r = $alerts->send($this->tenant(), test: true);

        return back()->with(isset($r['skipped']) ? 'error' : 'success', isset($r['skipped'])
            ? 'No se envió: ' . $r['skipped'] . '.'
            : 'Prueba enviada: correo ' . ($r['email'] ? 'sí' : 'no') . ", Telegram a {$r['telegram']} chat(s).");
    }

    private function tenant(): Tenant
    {
        $tenant = Tenant::find(current_tenant_id_or_null());
        abort_unless($tenant, 422, 'Elige una empresa.');

        return $tenant;
    }
}
