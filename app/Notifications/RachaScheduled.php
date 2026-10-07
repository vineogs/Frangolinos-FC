<?php

namespace App\Notifications;

use App\Models\RachaDay;
use Illuminate\Notifications\Notification;

class RachaScheduled extends Notification
{
    public function __construct(public RachaDay $day, public bool $changed = false) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'day_id' => $this->day->id,
            'title' => $this->changed ? 'Horário do racha alterado' : 'Novo dia de racha!',
            'date' => $this->day->date,
            'time' => $this->day->time,
            'end_time' => $this->day->end_time,
            'location' => $this->day->location,
        ];
    }
}
