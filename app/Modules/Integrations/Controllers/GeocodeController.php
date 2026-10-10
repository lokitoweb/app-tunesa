<?php

namespace App\Modules\Integrations\Controllers;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Integrations\Services\Geocoding\GeocodingException;
use App\Modules\Integrations\Services\Geocoding\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Geocodificación para el panel (mapa de sucursales, visor de marcaciones) y la app.
 *
 *   Panel (sesión):  GET /geocode/search?q=&limit=   GET /geocode/reverse?lat=&lng=
 *   API (token):     GET /api/geocode/reverse?lat=&lng=
 *
 * Usa el proveedor efectivo de la empresa en contexto; en la vista global
 * del Super Admin, el de la plataforma.
 */
class GeocodeController extends Controller
{
    public function __construct(private readonly GeocodingService $geocoding) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'     => ['required', 'string', 'min:3', 'max:200'],
            'limit' => ['sometimes', 'integer', 'between:1,10'],
        ], [
            'q.required' => 'Escribe una dirección para buscar.',
            'q.min'      => 'Escribe al menos 3 caracteres.',
        ]);

        try {
            $results = $this->geocoding->search($validated['q'], (int) ($validated['limit'] ?? 5));
        } catch (GeocodingException $e) {
            return $this->unavailable($e);
        }

        return response()->json(['success' => true, 'data' => $results]);
    }

    public function reverse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        try {
            $result = $this->geocoding->reverse((float) $validated['lat'], (float) $validated['lng']);
        } catch (GeocodingException $e) {
            return $this->unavailable($e);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function unavailable(GeocodingException $e): JsonResponse
    {
        Log::warning('Geocodificación no disponible: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'code'    => 'GEOCODING_UNAVAILABLE',
            'message' => 'El servicio de mapas no respondió. Intenta de nuevo en unos segundos.',
        ], 503);
    }
}
