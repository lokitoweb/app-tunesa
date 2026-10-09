<?php

namespace App\Services\Geocoding;

/** Proveedor de geocodificación (Nominatim, Google) */
interface Geocoder
{
    /** "nominatim" | "google" */
    public function name(): string;

    /**
     * @return list<array{label: string, lat: float, lng: float}>
     *
     * @throws GeocodingException
     */
    public function search(string $query, int $limit = 5): array;

    /**
     * Dirección legible del punto, o null si el proveedor no encontró nada.
     *
     * @throws GeocodingException
     */
    public function reverse(float $lat, float $lng): ?string;
}
