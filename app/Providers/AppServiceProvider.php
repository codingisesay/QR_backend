<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema; // ← add this
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
       // App\Providers\AppServiceProvider::register()
$this->app->singleton(\App\Services\Comm\SesEmailSender::class);
$this->app->singleton(\App\Services\Comm\TwilioSmsSender::class);
$this->app->singleton(\App\Services\Comm\FcmPushSender::class);
$this->app->singleton(\App\Services\Comm\LogChannelSender::class);
$this->app->singleton(\App\Services\Comm\CommSender::class);
$this->app->singleton(\App\Services\Pricing::class);
$this->app->singleton(\App\Services\WalletService::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Limit default varchar length so unique indexes fit under MySQL’s byte limit
        Schema::defaultStringLength(191);

        //   // Build the reset link to your frontend (or any URL you want)
        // ResetPassword::createUrlUsing(function ($notifiable, string $token) {
        //     $frontend = rtrim(env('FRONTEND_URL', config('app.url')), '/');
        //     // Final link will look like: https://your-frontend/reset-password?token=...&email=...
        //     return $frontend.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        // });

            // Verification email → SPA landing after backend verifies
    VerifyEmail::toMailUsing(function ($notifiable, $url) {
        // $url is the signed backend link. We’ll show it in the email.
        return (new MailMessage)
            ->subject('Verify your email')
            ->greeting('Welcome!')
            ->line('Please verify your email to activate your account.')
            ->action('Verify Email', $url)
            ->line('If you did not create an account, no further action is required.');
    });

    // Password reset → link targets your SPA
    ResetPassword::createUrlUsing(function ($notifiable, string $token) {
        // frontend will read token & email and POST /auth/reset-password
        $frontend = rtrim(config('app.frontend_url', env('FRONTEND_URL')), '/');
        $email = urlencode($notifiable->getEmailForPasswordReset());
        return "{$frontend}/authentication/set-password?token={$token}&email={$email}";
    });
    }


}
