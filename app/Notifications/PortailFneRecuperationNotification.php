<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PortailFneRecuperationNotification extends Notification
{
    use Queueable;

    public function __construct(private array $rapport) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->rapport + [
            'titre' => 'Récupération FNE terminée',
            'recuperee_le' => now()->toIso8601String(),
        ];
    }
}
