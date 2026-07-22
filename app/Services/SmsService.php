<?php

namespace App\Services;

use App\Support\PhoneHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function isConfigured(): bool
    {
        return (bool) (
            config('services.twilio.sid')
            && config('services.twilio.token')
            && config('services.twilio.from')
        );
    }

    public function sendOtp(string $phone, string $code): void
    {
        $message = "Tu codigo Viajes Suena: {$code}. Valido 5 minutos. No lo compartas.";

        if ($this->isConfigured()) {
            $this->sendViaTwilio($phone, $message);

            return;
        }

        Log::info('[SMS OTP] Telefono: ' . $phone . ' | Codigo: ' . $code);
    }

    private function sendViaTwilio(string $phone, string $message): void
    {
        $to = PhoneHelper::toE164($phone);

        if (!$to) {
            throw new \InvalidArgumentException('Numero de telefono invalido.');
        }

        $sid = config('services.twilio.sid');
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $response = Http::withBasicAuth($sid, config('services.twilio.token'))
            ->asForm()
            ->post($url, [
                'To' => $to,
                'From' => config('services.twilio.from'),
                'Body' => $message,
            ]);

        if (!$response->successful()) {
            Log::error('[Twilio] Error al enviar SMS', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('No se pudo enviar el SMS. Intenta mas tarde.');
        }
    }
}
