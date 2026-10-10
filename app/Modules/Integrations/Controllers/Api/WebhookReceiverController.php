<?php

namespace App\Modules\Integrations\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Integrations\Jobs\SendTenantWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receptor de webhooks de la propia plataforma: la URL con la que se precargan
 * "URL del webhook" y "Endpoint de notificaciones" mientras la empresa no
 * tenga la de su sistema. Verifica la firma X-Tunesa-Signature con el
 * secreto de la empresa (o de la plataforma, sin slug) y responde si es válida,
 * así "Probar Webhook Ahora" funciona desde el primer momento.
 *
 * POST /api/v1/webhooks/receiver/{slug?}
 */
class WebhookReceiverController extends Controller
{
    public function receive(Request $request, ?string $slug = null): JsonResponse
    {
        $tenant = null;
        if ($slug !== null) {
            $tenant = Tenant::where('slug', $slug)->first();
            if (! $tenant) {
                return response()->json(['ok' => false, 'message' => 'Empresa no encontrada.'], 404);
            }
        }

        $secret    = TenantSetting::rowFor($tenant)?->readableWebhookSecret();
        $signature = (string) $request->header('X-Tunesa-Signature');
        $expected  = $secret !== null ? 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret) : null;

        if ($expected === null || ! hash_equals($expected, $signature)) {
            return response()->json([
                'ok'      => false,
                'message' => $signature === '' ? 'Falta la firma X-Tunesa-Signature.' : 'La firma no coincide con el secreto de la empresa.',
            ], 401);
        }

        return response()->json([
            'ok'              => true,
            'message'         => 'Recibido por TuneSA: firma válida.',
            'event'           => $request->header('X-Tunesa-Event') ?? $request->json('event'),
            'signature_valid' => true,
        ]);
    }

    /** URL del receptor para la empresa (o la plataforma) */
    public static function urlFor(?Tenant $tenant, string $target = SendTenantWebhook::TARGET_WEBHOOK): string
    {
        $url = route('integrations.receiver', array_filter(['slug' => $tenant?->slug]));

        return $target === SendTenantWebhook::TARGET_NOTIFICATION ? $url . '?canal=notificaciones' : $url;
    }
}
