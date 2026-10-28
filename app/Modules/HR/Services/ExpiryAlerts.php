<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\HR\Notifications\ExpiryDigest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Aviso diario de vencimientos por correo y Telegram a los destinatarios de la empresa. Avisa lo que
 * llega justo a uno de los días configurados (30, 15, 7, 1 y el mismo día por defecto): cada cosa
 * se avisa en esos días y no todos los días. Un canal que falla no impide el otro.
 */
class ExpiryAlerts
{
    public function __construct(private readonly ExpiryWatch $watch) {}

    /** @return array{items: int, email: bool, telegram: int, skipped?: string} */
    public function send(Tenant $tenant, bool $test = false): array
    {
        $config = TenantSetting::forTenant($tenant)->expiryAlerts();
        if (! $config['emails'] && ! $config['chats']) {
            return ['items' => 0, 'email' => false, 'telegram' => 0, 'skipped' => 'sin destinatarios'];
        }

        $items = $test
            ? $this->watch->items($tenant, 30, 0)->take(10)
            : $this->watch->items($tenant, max($config['days'] ?: [0]), 0)->filter(fn ($i) => in_array($i['days_left'], $config['days'], true))->values();
        if ($items->isEmpty() && ! $test) {
            return ['items' => 0, 'email' => false, 'telegram' => 0, 'skipped' => 'nada que avisar hoy'];
        }
        // Una vez por día y empresa (el scheduler y una corrida a mano no duplican)
        if (! $test && ! Cache::add("expiry-alerts:{$tenant->id}:" . now()->toDateString(), true, now()->addDays(2))) {
            return ['items' => $items->count(), 'email' => false, 'telegram' => 0, 'skipped' => 'ya se avisó hoy'];
        }

        $email = false;
        if ($config['emails']) {
            $email = (bool) rescue(function () use ($tenant, $config, $items, $test) {
                Notification::route('mail', $config['emails'])->notify(new ExpiryDigest($tenant, $items, $test));

                return true;
            }, false, report: true);
        }

        $telegram = 0;
        if ($config['chats'] && $config['telegram_token']) {
            $text = $this->telegramText($tenant, $items, $test);
            foreach ($config['chats'] as $chat) {
                $telegram += (int) rescue(fn () => Http::timeout(10)->asJson()
                    ->post(rtrim(config('services.telegram.api_url'), '/') . "/bot{$config['telegram_token']}/sendMessage", [
                        'chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
                    ])->throw()->successful(), false, report: true);
            }
        }

        return ['items' => $items->count(), 'email' => $email, 'telegram' => $telegram];
    }

    public static function when(int $daysLeft): string
    {
        return match (true) {
            $daysLeft < 0   => 'venció hace ' . abs($daysLeft) . ' día(s)',
            $daysLeft === 0 => 'vence hoy',
            $daysLeft === 1 => 'vence mañana',
            default         => "vence en {$daysLeft} días",
        };
    }

    private function telegramText(Tenant $tenant, Collection $items, bool $test): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $lines = ['<b>' . ($test ? 'Prueba de alertas · ' : 'Vencimientos · ') . $e($tenant->company_name) . '</b>'];
        foreach ($items as $i) {
            $lines[] = "• {$e($i['kind_label'])}: <b>{$e($i['title'])}</b>" . ($i['who'] ? " — {$e($i['who'])}" : '')
                . ' · ' . self::when($i['days_left']) . ' (' . \Illuminate\Support\Carbon::parse($i['date'])->format('d/m/Y') . ')';
        }
        if ($items->isEmpty()) {
            $lines[] = 'Las alertas están bien configuradas. No hay vencimientos en los próximos 30 días.';
        }

        return mb_substr(implode("\n", $lines), 0, 4000);
    }
}
