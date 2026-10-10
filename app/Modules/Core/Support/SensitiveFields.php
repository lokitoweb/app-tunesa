<?php

namespace App\Modules\Core\Support;

/**
 * Lista central de campos sensibles: nunca se guardan ni se muestran en claro
 * (bitácora de auditoría, vistas, respuestas). Ver AuditLogger y Labels.
 *
 *  - SECRETS: claves y tokens → en la bitácora se guarda la máscara "••••••••1234"
 *    (o "(cambiado)" si el valor es un documento entero, como el JSON de FCM).
 *  - ACCOUNT_NUMBERS: cuentas bancarias → máscara con los últimos 4 dígitos.
 *  - OPAQUE: no aportan nada visible (contraseñas, códigos 2FA) → "(cambiado)".
 */
final class SensitiveFields
{
    public const CHANGED = '(cambiado)';
    public const REMOVED = '(eliminado)';

    /** Se enmascaran dejando ver los últimos 4 caracteres */
    public const SECRETS = [
        'webhook_secret',
        'maps_api_key',
        'geocoding_api_key',
        'api_key',
        'api_token',
        'fcm_server_key',
        'fcm_token',
    ];

    /** Cuentas bancarias y códigos previsionales: últimos 4 dígitos */
    public const ACCOUNT_NUMBERS = [
        'bank_account_number',
        'interbank_code',
        'cts_account_number',
        'account_number',
        'pension_code',
    ];

    /** Nunca se muestra nada del valor: solo que cambió */
    public const OPAQUE = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'fcm_service_account_json',
        'token',
    ];

    /** Patrones adicionales (cualquier *_secret, *_token, *_api_key, two_factor_*, password*) */
    private const PATTERNS = [
        '/(^|_)secret$/',
        '/(^|_)token$/',
        '/api_key$/',
        '/^two_factor_/',
        '/^password/',
        '/service_account/',
    ];

    public static function isSensitive(string $field): bool
    {
        if (in_array($field, self::SECRETS, true) || in_array($field, self::ACCOUNT_NUMBERS, true) || in_array($field, self::OPAQUE, true)) {
            return true;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $field)) {
                return true;
            }
        }

        return false;
    }

    public static function isOpaque(string $field): bool
    {
        if (in_array($field, self::OPAQUE, true)) {
            return true;
        }

        return (bool) preg_match('/^(password|two_factor_)|service_account|_json$/', $field);
    }

    /**
     * Valor seguro para guardar o mostrar: null/'' se conservan (para saber
     * que no había nada), lo demás se enmascara.
     */
    public static function mask(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (self::isMasked($value)) {
            return $value;
        }
        if (self::isOpaque($field) || ! is_scalar($value)) {
            return self::CHANGED;
        }

        return self::maskString((string) $value);
    }

    /** "sk_live_abcdef1234" → "••••••••1234" (si es corto, sin pista) */
    public static function maskString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('•', 8) . (mb_strlen($value) > 8 ? mb_substr($value, -4) : '');
    }

    /** ¿Ya viene enmascarado? (evita doble máscara) */
    public static function isMasked(mixed $value): bool
    {
        return is_string($value) && ($value === self::CHANGED || $value === self::REMOVED || str_starts_with($value, '••••'));
    }

    /**
     * Enmascara recursivamente las claves sensibles de un arreglo (contexto de
     * la bitácora, payloads). Las listas de nombres de campo se dejan tal cual.
     */
    public static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::mask($key, $value);
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }

    /** Partes "1.2.3.4" → "1.2.x.x"; IPv6 → primeros dos bloques */
    public static function maskIp(?string $ip): ?string
    {
        if (blank($ip)) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.x.x";
        }
        $blocks = explode(':', $ip);

        return implode(':', array_slice($blocks, 0, 2)) . ':…';
    }
}
