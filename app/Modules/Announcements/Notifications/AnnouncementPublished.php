<?php

namespace App\Modules\Announcements\Notifications;

use App\Modules\Announcements\Models\Announcement;
use App\Modules\Core\Notifications\AppNotification;
use Illuminate\Support\Str;

/** Push + bandeja: comunicado nuevo de RR. HH. */
class AnnouncementPublished extends AppNotification
{
    public function __construct(public readonly Announcement $announcement) {}

    public function type(): string
    {
        return $this->announcement->category === 'urgent' ? 'announcement.urgent' : 'announcement.published';
    }

    public function title(): string
    {
        $prefix = match (true) {
            $this->announcement->category === 'urgent'  => '⚠️ ',
            $this->announcement->category === 'meeting' => '📅 ',
            $this->announcement->is_important           => '📢 ',
            default                                     => '',
        };

        return $prefix . $this->announcement->title;
    }

    public function body(): string
    {
        $when = $this->announcement->event_at
            ? $this->announcement->event_at->timezone(tenant_timezone())->isoFormat('dddd D [de] MMMM, h:mm a') . '. '
            : '';

        return $when . Str::limit(strip_tags($this->announcement->body), 140);
    }

    public function payload(): array
    {
        return ['announcement_id' => $this->announcement->id, 'screen' => 'announcements'];
    }
}
