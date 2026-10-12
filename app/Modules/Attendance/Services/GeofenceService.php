<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Models\Branch;

/**
 * GeofenceService
 *
 * Implementa la fórmula de Haversine para calcular la distancia exacta
 * entre dos puntos GPS sobre la superficie esférica de la Tierra.
 *
 * La fórmula Haversine es más precisa que la fórmula de distancia euclidiana
 * porque tiene en cuenta la curvatura de la Tierra. Para distancias cortas
 * (< 10 km, como nuestro caso de sucursales), el error es < 0.5%.
 *
 * Referencia matemática:
 *   a = sin²(Δlat/2) + cos(lat1) · cos(lat2) · sin²(Δlng/2)
 *   c = 2 · atan2(√a, √(1−a))
 *   d = R · c
 *   donde R = 6,371,000 metros (radio medio de la Tierra)
 */
class GeofenceService
{
    /**
     * Radio medio de la Tierra en metros.
     * Valor aceptado por la Unión Astronómica Internacional.
     */
    private const EARTH_RADIUS_METERS = 6_371_000;

    // =========================================================================
    // MÉTODO PRINCIPAL: Verificar si está dentro de la geocerca
    // =========================================================================

    /**
     * Verifica si unas coordenadas GPS están dentro del radio permitido
     * de una sucursal.
     *
     * Con la precisión de la lectura (accuracy, en m) hay una zona de borde:
     * fuera del radio pero a menos de min(accuracy, tolerancia de la sede)
     * de él. La duda ahí es del GPS: isWithinGeofence sigue en false y
     * isEdge en true, y la política decide (AttendanceService la acepta
     * como observada con GEOFENCE_EDGE).
     *
     * @param  float   $lat            Latitud del dispositivo móvil
     * @param  float   $lng            Longitud del dispositivo móvil
     * @param  Branch  $branch         Sucursal con su geocerca definida
     * @param  ?float  $accuracy       Precisión de la lectura en metros (null = sin zona de borde)
     * @return GeofenceResult          DTO con resultado, distancia y mensaje
     */
    public function check(float $lat, float $lng, Branch $branch, ?float $accuracy = null): GeofenceResult
    {
        $distance = $this->calculateDistance(
            lat1: $lat,
            lng1: $lng,
            lat2: (float) $branch->latitude,
            lng2: (float) $branch->longitude,
        );

        $radius       = $branch->allowed_radius_meters;
        $isInside     = $distance <= $radius;
        $margin       = $accuracy !== null && $accuracy > 0 ? min($accuracy, $branch->edgeToleranceMeters()) : 0;
        $isEdge       = ! $isInside && $distance <= $radius + $margin;
        $excessMeters = max(0, (int) round($distance - $radius));

        return new GeofenceResult(
            isWithinGeofence: $isInside,
            distanceMeters:   (int) round($distance),
            allowedRadius:    $radius,
            branchName:       $branch->name,
            excessMeters:     $excessMeters,
            isEdge:           $isEdge,
        );
    }

    // =========================================================================
    // FÓRMULA HAVERSINE
    // =========================================================================

    /**
     * Calcula la distancia en metros entre dos puntos GPS usando Haversine.
     *
     * @param  float $lat1  Latitud punto 1 (dispositivo)
     * @param  float $lng1  Longitud punto 1 (dispositivo)
     * @param  float $lat2  Latitud punto 2 (centro de sucursal)
     * @param  float $lng2  Longitud punto 2 (centro de sucursal)
     * @return float        Distancia en metros
     */
    public function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        // Convertir grados a radianes (requerimiento de las funciones trigonométricas de PHP)
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $lng1Rad = deg2rad($lng1);
        $lng2Rad = deg2rad($lng2);

        // Diferencias de coordenadas
        $deltaLat = $lat2Rad - $lat1Rad;
        $deltaLng = $lng2Rad - $lng1Rad;

        // Fórmula Haversine:
        // a = sin²(Δlat/2) + cos(lat1) · cos(lat2) · sin²(Δlng/2)
        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;

        // c = 2 · atan2(√a, √(1−a))
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        // Distancia = Radio_Tierra × c
        return self::EARTH_RADIUS_METERS * $c;
    }

    // =========================================================================
    // MÉTODOS AUXILIARES
    // =========================================================================

    /**
     * Retorna la distancia como string legible.
     * Ej: "47 m", "1.2 km"
     */
    public function formatDistance(float $meters): string
    {
        if ($meters < 1000) {
            return round($meters) . ' m';
        }

        return round($meters / 1000, 1) . ' km';
    }

    /**
     * Valida si unas coordenadas tienen valores geográficamente válidos.
     */
    public function areValidCoordinates(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90
            && $lng >= -180 && $lng <= 180
            && ! ($lat === 0.0 && $lng === 0.0); // (0,0) es el Mar de Guinea, no válido para uso real
    }

    /**
     * Calcula el bounding box para pre-filtrar sucursales cercanas por SQL
     * antes de aplicar Haversine (optimización de performance).
     *
     * @param  float $lat     Latitud central
     * @param  float $lng     Longitud central
     * @param  float $radiusM Radio en metros
     * @return array          ['min_lat', 'max_lat', 'min_lng', 'max_lng']
     */
    public function getBoundingBox(float $lat, float $lng, float $radiusM): array
    {
        // 1 grado de latitud ≈ 111,320 metros
        $latDelta = $radiusM / 111_320;

        // Los grados de longitud varían según la latitud
        $lngDelta = $radiusM / (111_320 * cos(deg2rad($lat)));

        return [
            'min_lat' => $lat - $latDelta,
            'max_lat' => $lat + $latDelta,
            'min_lng' => $lng - $lngDelta,
            'max_lng' => $lng + $lngDelta,
        ];
    }
}

// =============================================================================
// DTO: Resultado de la verificación de geocerca
// =============================================================================

/**
 * GeofenceResult — Value Object (DTO)
 * Encapsula el resultado completo de la validación GPS.
 */
readonly class GeofenceResult
{
    public function __construct(
        public readonly bool   $isWithinGeofence,
        public readonly int    $distanceMeters,
        public readonly int    $allowedRadius,
        public readonly string $branchName,
        public readonly int    $excessMeters,
        /** Fuera del radio pero dentro del margen de la precisión GPS (zona de borde) */
        public readonly bool   $isEdge = false,
    ) {}

    /**
     * Mensaje legible para devolver al cliente (app móvil).
     */
    public function getMessage(): string
    {
        if ($this->isWithinGeofence) {
            return "Ubicación válida. Estás a {$this->distanceMeters}m de {$this->branchName}.";
        }

        return "Estás fuera del área permitida. Distancia: {$this->distanceMeters}m. "
            . "Máximo permitido: {$this->allowedRadius}m. "
            . "Necesitas acercarte {$this->excessMeters}m más.";
    }

    /** Convierte a array para incluir en la respuesta JSON */
    public function toArray(): array
    {
        return [
            'within_geofence'  => $this->isWithinGeofence,
            'distance_meters'  => $this->distanceMeters,
            'allowed_radius'   => $this->allowedRadius,
            'excess_meters'    => $this->excessMeters,
            'edge'             => $this->isEdge,
            'branch_name'      => $this->branchName,
            'message'          => $this->getMessage(),
        ];
    }
}
