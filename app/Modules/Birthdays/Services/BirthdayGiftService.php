<?php

namespace App\Modules\Birthdays\Services;

use App\Modules\Birthdays\Models\BirthdayGift;
use App\Modules\Birthdays\Notifications\BirthdayGiftReceived;
use App\Modules\Core\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Regalos animados de cumpleaños entre compañeros.
 *
 * Todo se decide con la fecha de la empresa (tenant_timezone, America/Lima por
 * defecto): solo se envía y se reproduce el día del cumpleaños; a las 23:59
 * caduca y queda como historial estático. La app tiene una animación Lottie por
 * cada clave del catálogo (mobile/assets/gifts/<clave>.json).
 */
class BirthdayGiftService
{
    public const CATALOG = [
        'lion'        => ['label' => 'León rugiendo', 'emoji' => '🦁'],
        'crown'       => ['label' => 'Corona brillante', 'emoji' => '👑'],
        'galaxy'      => ['label' => 'Galaxia en movimiento', 'emoji' => '🌌'],
        'space_whale' => ['label' => 'Ballena espacial', 'emoji' => '🐋'],
        'cake'        => ['label' => 'Pastel de fiesta', 'emoji' => '🎂'],
        'fireworks'   => ['label' => 'Fuegos artificiales', 'emoji' => '🎆'],
    ];

    public function __construct(private readonly BirthdayService $birthdays) {}

    public static function catalog(): array
    {
        return collect(self::CATALOG)->map(fn ($g, $key) => ['type' => $key] + $g)->values()->all();
    }

    public function isBirthday(User $user, Carbon $today): bool
    {
        return $user->birth_md && in_array((int) $user->birth_md, $this->birthdays->mdFor($today), true);
    }

    /**
     * Regalos del último cumpleaños de $recipient (vistos por $viewer).
     * playable: solo el mismo día; después, historial sin animación.
     */
    public function board(User $recipient, User $viewer, Carbon $today): array
    {
        $isToday = $this->isBirthday($recipient, $today);
        $date    = $isToday
            ? $today->toDateString()
            : BirthdayGift::where('user_id', $recipient->id)->max('date');

        $gifts = $date
            ? BirthdayGift::where('user_id', $recipient->id)->whereDate('date', $date)
                ->with('sender:id,first_name,last_name,avatar_path')->latest('id')->get()
            : collect();

        $canSend = $isToday && $recipient->id !== $viewer->id;

        return [
            'is_today'   => $isToday,
            'is_self'    => $recipient->id === $viewer->id,
            'date'       => $date ? Carbon::parse($date)->toDateString() : null,
            'expires_at' => $isToday ? $today->copy()->endOfDay()->toIso8601String() : null,
            'can_send'   => $canSend,
            'sent_by_me' => $canSend ? $gifts->where('sender_id', $viewer->id)->pluck('gift_type')->values() : [],
            'catalog'    => self::catalog(),
            'gifts'      => $gifts->map(fn (BirthdayGift $g) => $this->present($g, $today))->values(),
        ];
    }

    /** Envía el regalo; null si ese remitente ya mandó ese mismo regalo hoy */
    public function send(User $recipient, User $sender, string $type, Carbon $today): ?BirthdayGift
    {
        try {
            $gift = BirthdayGift::create([
                'tenant_id' => $recipient->tenant_id,
                'user_id'   => $recipient->id,
                'sender_id' => $sender->id,
                'gift_type' => $type,
                'date'      => $today->toDateString(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $recipient->notify(new BirthdayGiftReceived($sender, $type));

        return $gift;
    }

    public function present(BirthdayGift $gift, Carbon $today): array
    {
        $sender = $gift->sender;
        $meta   = self::CATALOG[$gift->gift_type] ?? ['label' => 'Regalo', 'emoji' => '🎁'];

        return [
            'id'        => $gift->id,
            'type'      => $gift->gift_type,
            'label'     => $meta['label'],
            'emoji'     => $meta['emoji'],
            'date'      => $gift->date->toDateString(),
            'sent_at'   => $gift->created_at?->toIso8601String(),
            'playable'  => $gift->date->toDateString() === $today->toDateString(),
            'sender'    => [
                'id'         => $sender?->id,
                'name'       => $sender?->full_name ?? 'Un compañero',
                'first_name' => $sender?->first_name,
                'initials'   => $sender ? mb_strtoupper(mb_substr((string) $sender->first_name, 0, 1) . mb_substr((string) $sender->last_name, 0, 1)) : '?',
                'avatar_url' => $sender?->avatar_path ? Storage::disk('public')->url($sender->avatar_path) : null,
            ],
        ];
    }
}
