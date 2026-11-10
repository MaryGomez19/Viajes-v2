<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class PackageInfoPriceValidation
{
    /**
     * Valida que price_adult + price_junior + price_child no superen package_total_cost
     * y que, con los tres precios presentes, la suma coincida con el total.
     *
     * @param  array<string, mixed>  $data
     */
    public static function assert(array $data): void
    {
        $total = self::nullableAmount($data['package_total_cost'] ?? null);
        $adult = self::nullableAmount($data['price_adult'] ?? null);
        $junior = self::nullableAmount($data['price_junior'] ?? null);
        $child = self::nullableAmount($data['price_child'] ?? null);

        if ($total === null) {
            return;
        }

        $adults = max(0, (int) ($data['adults'] ?? 0));
        $juniors = max(0, (int) ($data['juniors'] ?? 0));
        $children = max(0, (int) ($data['children'] ?? 0));
        $pax = $adults + $juniors + $children;

        if ($pax > 0) {
            $sumCents =
                self::toCents($adult) * $adults
                + self::toCents($junior) * $juniors
                + self::toCents($child) * $children;
        } else {
            $sumCents = self::toCents($adult) + self::toCents($junior) + self::toCents($child);
        }

        $totalCents = self::toCents($total);

        if ($sumCents > $totalCents) {
            $over = number_format(($sumCents - $totalCents) / 100, 2, '.', '');

            throw ValidationException::withMessages([
                'price_breakdown' => [
                    "La suma por edad no puede superar el costo real plataforma. Exceso: {$over}.",
                ],
            ]);
        }

        if ($adult !== null && $junior !== null && $child !== null && $sumCents !== $totalCents) {
            $diff = number_format(abs($sumCents - $totalCents) / 100, 2, '.', '');

            throw ValidationException::withMessages([
                'price_breakdown' => [
                    "Los precios por edad deben sumar exactamente el costo real plataforma. Diferencia: {$diff}.",
                ],
            ]);
        }
    }

    public static function nullableAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $num = (float) $value;
        if ($num < 0) {
            return null;
        }

        return number_format($num, 2, '.', '');
    }

    private static function toCents(?string $amount): int
    {
        if ($amount === null) {
            return 0;
        }

        return (int) round((float) $amount * 100);
    }
}
