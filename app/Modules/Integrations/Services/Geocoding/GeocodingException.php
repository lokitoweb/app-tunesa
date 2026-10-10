<?php

namespace App\Modules\Integrations\Services\Geocoding;

use RuntimeException;

/** El proveedor de geocodificación no respondió o rechazó la consulta */
class GeocodingException extends RuntimeException {}
