<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\Hotel;
use App\Models\Package;
use App\Models\Reservation;
use App\Models\SupplierAccount;
use App\Models\Transport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SupplierAccountService
{
    public const TRIP_PAYMENT_ALERT_DAYS = 3;

    /**
     * @return array<int, array{
     *   key: string,
     *   label: string,
     *   description: string,
     *   supplier_name: ?string,
     *   amount: float,
     * }>
     */
    public static function breakdownLines(Reservation $reservation): array
    {
        $reservation->loadMissing(['package.city', 'flight', 'transport', 'hotel']);

        $lines = [];

        $package = $reservation->package;
        if ($package) {
            $amount = self::resolveSupplierAmount(
                $package->supplier_payment,
                $package->provider_cost,
            );
            if ($amount !== null) {
                $name = $package->name ?? 'Paquete';
                $city = $package->city?->name;
                $lines[] = [
                    'key' => 'package',
                    'label' => $reservation->booking_channel === 'tour' ? 'Tour' : 'Paquete',
                    'description' => $city ? "{$name} · {$city}" : $name,
                    'supplier_name' => null,
                    'amount' => $amount,
                ];
            }
        }

        $flight = $reservation->flight;
        if ($flight) {
            $amount = self::resolveSupplierAmount(
                $flight->supplier_payment,
                $flight->provider_cost,
            );
            if ($amount !== null) {
                $lines[] = [
                    'key' => 'flight',
                    'label' => 'Vuelo',
                    'description' => trim(($flight->airline_name ?? 'Vuelo').' · '.($flight->destination ?? '')),
                    'supplier_name' => $flight->airline_name,
                    'amount' => $amount,
                ];
            }
        }

        $transport = $reservation->transport;
        if ($transport) {
            $amount = self::resolveSupplierAmount(
                $transport->supplier_payment,
                $transport->provider_cost,
            );
            if ($amount !== null) {
                $lines[] = [
                    'key' => 'transport',
                    'label' => 'Transporte',
                    'description' => trim(($transport->company ?? 'Transporte').' · '.($transport->destination ?? '')),
                    'supplier_name' => $transport->supplier ?: $transport->company,
                    'amount' => $amount,
                ];
            }
        }

        $hotel = $reservation->hotel;
        if ($hotel) {
            $amount = self::resolveSupplierAmount(null, $hotel->provider_cost);
            if ($amount !== null) {
                $lines[] = [
                    'key' => 'hotel',
                    'label' => 'Hotel',
                    'description' => trim(($hotel->name ?? 'Hotel').' · '.($hotel->destination ?? '')),
                    'supplier_name' => $hotel->supplier,
                    'amount' => $amount,
                ];
            }
        }

        return $lines;
    }

    public static function calculateTotalOwed(Reservation $reservation): float
    {
        $total = 0.0;
        foreach (self::breakdownLines($reservation) as $line) {
            $total += $line['amount'];
        }

        return round($total, 4);
    }

    public static function ensureForReservation(Reservation $reservation): SupplierAccount
    {
        $account = SupplierAccount::firstOrCreate(
            ['reservation_id' => $reservation->id],
            [
                'total_amount' => 0,
                'state' => $reservation->state === 'canceled' ? 'canceled' : 'pending',
            ],
        );

        return self::syncTotals($account);
    }

    public static function syncTotals(SupplierAccount $account): SupplierAccount
    {
        $reservation = $account->reservation ?? Reservation::find($account->reservation_id);
        if (! $reservation) {
            return $account;
        }

        if ($reservation->state === 'canceled') {
            $account->update(['state' => 'canceled']);

            return $account->fresh();
        }

        $total = self::calculateTotalOwed($reservation);
        $paid = $account->paidAmount();

        if ($total < $paid) {
            $total = $paid;
        }

        $account->update(['total_amount' => $total]);
        $account->recalculateState();

        return $account->fresh(['supplierPayments', 'reservation.user', 'reservation.package.city']);
    }

    public static function resolveSupplierAmount(mixed $supplierPayment, mixed $providerCost): ?float
    {
        $raw = $supplierPayment ?? $providerCost;
        if ($raw === null || $raw === '') {
            return null;
        }

        $amount = (float) $raw;

        return $amount > 0 ? round($amount, 4) : null;
    }

    /**
     * Cuentas con saldo pendiente y salida dentro de N días (o ya pasada).
     *
     * @param  Builder<SupplierAccount>  $query
     */
    public static function applyTripPaymentAlertScope(Builder $query): void
    {
        $until = now()->addDays(self::TRIP_PAYMENT_ALERT_DAYS)->toDateString();

        $query->whereIn('state', ['pending', 'partial'])
            ->whereHas('reservation', function ($q) use ($until) {
                $q->where('state', '!=', 'canceled')
                    ->whereNotNull('departure_date')
                    ->whereDate('departure_date', '<=', $until);
            });
    }

    public static function countTripPaymentAlerts(): int
    {
        $query = SupplierAccount::query()
            ->where('total_amount', '>', 0);

        self::applyTripPaymentAlertScope($query);

        return $query->count();
    }

    /**
     * @return array{days_until_departure: int, level: string, message: string}|null
     */
    public static function buildTripPaymentAlert(
        ?Reservation $reservation,
        float $balanceDue,
        string $state,
    ): ?array {
        if (! $reservation?->departure_date) {
            return null;
        }

        if ($balanceDue <= 0.001 || in_array($state, ['paid', 'canceled'], true)) {
            return null;
        }

        $departure = Carbon::parse($reservation->departure_date)->startOfDay();
        $today = now()->startOfDay();
        $daysUntil = $departure->gte($today)
            ? (int) $today->diffInDays($departure)
            : -((int) $departure->diffInDays($today));

        if ($daysUntil > self::TRIP_PAYMENT_ALERT_DAYS) {
            return null;
        }

        if ($daysUntil < 0) {
            return [
                'days_until_departure' => $daysUntil,
                'level' => 'urgent',
                'message' => 'El viaje ya inició y aún hay saldo con el proveedor.',
            ];
        }

        $message = match ($daysUntil) {
            0 => 'Salida hoy: liquida el pago al proveedor.',
            1 => 'Salida mañana: liquida el pago al proveedor.',
            default => "Salida en {$daysUntil} días: liquida el pago al proveedor.",
        };

        return [
            'days_until_departure' => $daysUntil,
            'level' => $daysUntil <= 1 ? 'urgent' : 'warning',
            'message' => $message,
        ];
    }
}
