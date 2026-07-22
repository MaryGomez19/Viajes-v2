<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');

        ResetPassword::createUrlUsing(function ($user, string $token) use ($frontendUrl) {
            return $frontendUrl
                . '/reset-password?token=' . $token
                . '&email=' . urlencode($user->email);
        });

        ResetPassword::toMailUsing(function ($notifiable, string $token) use ($frontendUrl) {
            $url = $frontendUrl
                . '/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());

            return (new MailMessage)
                ->subject('Restablecer contraseña — Viajes Sueña')
                ->greeting('Hola')
                ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
                ->action('Restablecer contraseña', $url)
                ->line('Este enlace expira en 60 minutos.')
                ->line('Si no solicitaste este cambio, puedes ignorar este correo.')
                ->salutation('Saludos, Viajes Sueña');
        });
    }
}
