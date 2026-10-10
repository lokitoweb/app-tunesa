<?php

namespace App\Modules\Integrations\Services\Push;

/** Resultado de un envío FCM (FcmSender::send) */
final class PushResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $status,
        public readonly int $ms,
        public readonly array $response = [],
        public readonly ?string $error = null,
        public readonly bool $transient = false,
        public readonly bool $tokenCleared = false,
        public readonly ?string $code = null,
    ) {}

    public static function success(int $status, array $response, float $started): self
    {
        return new self(true, $status, self::elapsed($started), $response);
    }

    public static function failure(string $error, ?int $status, float $started, array $response = [], bool $transient = false, bool $tokenCleared = false, ?string $code = null): self
    {
        return new self(false, $status, self::elapsed($started), $response, $error, $transient, $tokenCleared, $code);
    }

    /** {ok, status, ms, response} o {ok, status, ms, error} */
    public function toTestArray(): array
    {
        $base = ['ok' => $this->ok, 'status' => $this->status, 'ms' => $this->ms];

        return $this->ok
            ? $base + ['response' => $this->response]
            : $base + ['error' => $this->error, 'code' => $this->code];
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
