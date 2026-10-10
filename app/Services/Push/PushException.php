<?php

namespace App\Services\Push;

use RuntimeException;

/** Error al obtener credenciales o firmar para FCM */
class PushException extends RuntimeException {}
