<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestMail extends Command
{
    protected $signature = 'mail:test {email : Correo destino}';

    protected $description = 'Envía un correo de prueba para verificar la configuración SMTP';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (empty(config('mail.mailers.smtp.username')) || empty(config('mail.mailers.smtp.password'))) {
            $this->error('Falta MAIL_USERNAME o MAIL_PASSWORD en el archivo .env');
            $this->line('Genera una contraseña de aplicación en: https://myaccount.google.com/apppasswords');

            return self::FAILURE;
        }

        try {
            Mail::raw(
                'Correo de prueba de Viajes Sueña. Si ves esto, SMTP está configurado correctamente.',
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('Prueba SMTP — Viajes Sueña');
                }
            );

            $this->info("Correo de prueba enviado a {$email}. Revisa bandeja de entrada y spam.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('No se pudo enviar el correo: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
