<?php

namespace App\Support;

class PhoneHelper
{
    /** Deja solo dígitos y toma los últimos 10 (México). */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        } elseif (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    /** Formato E.164 para Twilio (+52XXXXXXXXXX). */
    public static function toE164(?string $phone, string $countryCode = '52'): ?string
    {
        $normalized = self::normalize($phone);

        return $normalized ? '+' . $countryCode . $normalized : null;
    }
}
