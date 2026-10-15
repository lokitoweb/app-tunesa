<?php

namespace App\Modules\Announcements\Services;

use App\Modules\Announcements\Models\Announcement;
use App\Modules\Announcements\Models\AnnouncementRead;
use App\Modules\Announcements\Notifications\AnnouncementPublished;
use App\Modules\Core\Jobs\NotifyAudience;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Comunicados: guardar (borrador, programado o enviar ya), enviar al
 * público por la cola y registrar lecturas.
 */
class AnnouncementService
{
    /** $mode: draft | schedule | send */
    public function save(Announcement $announcement, array $data, User $actor, string $mode): Announcement
    {
        abort_if($announcement->status === 'sent', 422, 'Un comunicado enviado no se puede editar.');

        DB::transaction(function () use ($announcement, $data, $actor, $mode) {
            $announcement->fill(collect($data)->except(['targets', 'mode'])->all());
            $announcement->created_by ??= $actor->id;
            $announcement->status = $mode === 'schedule' ? 'scheduled' : 'draft';
            if ($mode !== 'schedule') {
                $announcement->scheduled_at = null;
            }
            $announcement->save();
            $announcement->syncAudience($data['targets'] ?? []);
        });

        if ($mode === 'send') {
            $this->send($announcement);
        }

        return $announcement;
    }

    /** Envía ya: queda como enviado y la cola avisa (bandeja + push) por lotes */
    public function send(Announcement $announcement): void
    {
        $announcement->forceFill([
            'status'     => 'sent',
            'sent_at'    => now(),
            'recipients' => $announcement->audienceUsers()->count(),
        ])->save();

        NotifyAudience::dispatch(Announcement::class, $announcement->id, AnnouncementPublished::class);
    }

    public function markRead(Announcement $announcement, User $user): void
    {
        AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['tenant_id' => $announcement->tenant_id, 'read_at' => now()],
        );
    }
}
