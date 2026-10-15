<?php

namespace App\Modules\Announcements\Console;

use App\Modules\Announcements\Models\Announcement;
use App\Modules\Announcements\Services\AnnouncementService;
use App\Modules\Core\Models\Scopes\TenantScope;
use Illuminate\Console\Command;

/** Envía los comunicados programados cuya hora ya llegó (cada minuto) */
class DispatchScheduledAnnouncements extends Command
{
    protected $signature = 'announcements:dispatch';

    protected $description = 'Envía los comunicados programados que ya deben salir';

    public function handle(AnnouncementService $service): int
    {
        Announcement::withoutGlobalScope(TenantScope::class)
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get()
            ->each(function (Announcement $a) use ($service) {
                $service->send($a);
                $this->line("Enviado: {$a->title}");
            });

        return self::SUCCESS;
    }
}
