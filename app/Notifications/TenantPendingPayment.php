<?php
// app/Notifications/TenantPendingPayment.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Core\Tenant;

class TenantPendingPayment extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Tenant $tenant) {}

    public function via($notifiable) { return ['mail']; }

    public function toMail($notifiable)
    {
        $front = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');

        return (new MailMessage)
            ->subject('Your organization is almost ready')
            ->greeting('Hi '.$notifiable->name.'!')
            ->line('We created your organization: '.$this->tenant->name.' ('.$this->tenant->slug.').')
            ->line('Please complete the payment to activate your account.')
            ->action('Continue setup', $front.'/tenant/create')
            ->line('If you already paid, you can wait a moment while we finalize the setup.');
    }
}
