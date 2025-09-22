<?php
// app/Notifications/TenantActivated.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Core\Tenant;

class TenantActivated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Tenant $tenant) {}

    public function via($notifiable) { return ['mail']; }

    public function toMail($notifiable)
    {
        $front = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');

        return (new MailMessage)
            ->subject('Your organization is active')
            ->greeting('Great news!')
            ->line('Your organization "'.$this->tenant->name.'" is now active.')
            ->action('Open Dashboard', $front.'/dashboard')
            ->line('You can start generating secure QR codes right away.');
    }
}
