<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PaymentReceipt;
use App\Models\Reservation;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    private const ITEM_LIMIT = 20;
    private const DAYS_AHEAD = 14;
    private const DAYS_BEHIND = 3;

    public function count()
    {
        [$dateFrom, $dateTo] = $this->operationalDateRange();

        $total =
            PaymentReceipt::where('status', 'pending')->count() +
            Account::where('state', 'overdue')->count() +
            Reservation::where('state', 'pending')->count() +
            $this->operationalReservationQuery('flight', $dateFrom, $dateTo)->count() +
            $this->operationalReservationQuery('transport', $dateFrom, $dateTo)->count();

        return response()->json(['total' => $total]);
    }

    public function index(Request $request)
    {
        [$dateFrom, $dateTo] = $this->operationalDateRange();
        $items = [];

        $receipts = PaymentReceipt::query()
            ->where('status', 'pending')
            ->with(['user:id,name,last_name', 'reservation:id'])
            ->latest()
            ->limit(self::ITEM_LIMIT)
            ->get();

        foreach ($receipts as $receipt) {
            $clientName = $this->clientName($receipt->user);
            $items[] = [
                'id' => "receipt-{$receipt->id}",
                'text' => "{$clientName} subió un comprobante — Reserva #{$receipt->reservation_id}",
                'time' => $this->relativeTime($receipt->created_at?->toIso8601String()),
                'href' => '/admin/transferencias',
                'kind' => 'receipt_pending',
                'priority' => 100,
            ];
        }

        $accounts = Account::query()
            ->where('state', 'overdue')
            ->with(['reservation.user:id,name,last_name'])
            ->limit(self::ITEM_LIMIT)
            ->get();

        foreach ($accounts as $account) {
            $clientName = $this->clientName($account->reservation?->user);
            $balance = max(0, (float) $account->total_amount - $account->paidAmount());

            $items[] = [
                'id' => "overdue-{$account->id}",
                'text' => "Cuenta #{$account->id} vencida — {$clientName}, saldo " . $this->formatMoney($balance),
                'time' => 'Pago atrasado',
                'href' => "/admin/cuentas/{$account->id}",
                'kind' => 'account_overdue',
                'priority' => 95,
            ];
        }

        $pendingReservations = Reservation::query()
            ->where('state', 'pending')
            ->with([
                'user:id,name,last_name',
                'package:id,name',
                'package.city:id,name',
            ])
            ->latest()
            ->limit(self::ITEM_LIMIT)
            ->get(['id', 'user_id', 'package_id', 'state', 'reservation_date', 'departure_date']);

        foreach ($pendingReservations as $reservation) {
            $clientName = $this->clientName($reservation->user);
            $destination = $this->destinationLabel($reservation);

            $items[] = [
                'id' => "pending-res-{$reservation->id}",
                'text' => "Reserva pendiente #{$reservation->id} — {$clientName} ({$destination})",
                'time' => $this->relativeTime($reservation->reservation_date?->toDateString()),
                'href' => "/admin/reservas/detalle/{$reservation->id}",
                'kind' => 'reservation_pending',
                'priority' => 90,
            ];
        }

        $withoutFlight = $this->operationalReservationQuery('flight', $dateFrom, $dateTo)
            ->with([
                'user:id,name,last_name',
                'package:id,name',
                'package.city:id,name',
            ])
            ->limit(self::ITEM_LIMIT)
            ->get(['id', 'user_id', 'package_id', 'departure_date', 'state']);

        foreach ($withoutFlight as $reservation) {
            $destination = $this->destinationLabel($reservation);

            $items[] = [
                'id' => "no-flight-{$reservation->id}",
                'text' => "Reserva #{$reservation->id} sin vuelo — {$destination}",
                'time' => $this->departureLabel($reservation->departure_date?->toDateString()),
                'href' => "/admin/reservas/detalle/{$reservation->id}",
                'kind' => 'missing_flight',
                'priority' => 85,
            ];
        }

        $withoutTransport = $this->operationalReservationQuery('transport', $dateFrom, $dateTo)
            ->with([
                'user:id,name,last_name',
                'package:id,name',
                'package.city:id,name',
            ])
            ->limit(self::ITEM_LIMIT)
            ->get(['id', 'user_id', 'package_id', 'departure_date', 'state']);

        foreach ($withoutTransport as $reservation) {
            $destination = $this->destinationLabel($reservation);

            $items[] = [
                'id' => "no-transport-{$reservation->id}",
                'text' => "Reserva #{$reservation->id} sin transporte — {$destination}",
                'time' => $this->departureLabel($reservation->departure_date?->toDateString()),
                'href' => "/admin/reservas/detalle/{$reservation->id}",
                'kind' => 'missing_transport',
                'priority' => 80,
            ];
        }

        usort($items, function ($a, $b) {
            if ($b['priority'] !== $a['priority']) {
                return $b['priority'] <=> $a['priority'];
            }

            return strcmp($a['text'], $b['text']);
        });

        return response()->json([
            'notifications' => array_slice($items, 0, 40),
        ]);
    }

    private function operationalDateRange(): array
    {
        return [
            now()->subDays(self::DAYS_BEHIND)->toDateString(),
            now()->addDays(self::DAYS_AHEAD)->toDateString(),
        ];
    }

    private function operationalReservationQuery(string $missingRelation, string $dateFrom, string $dateTo)
    {
        return Reservation::query()
            ->whereDoesntHave($missingRelation)
            ->where('state', '!=', 'canceled')
            ->whereBetween('departure_date', [$dateFrom, $dateTo]);
    }

    private function clientName(?object $user): string
    {
        if (!$user) {
            return 'Cliente';
        }

        $name = trim(($user->name ?? '') . ' ' . ($user->last_name ?? ''));

        return $name !== '' ? $name : 'Cliente';
    }

    private function destinationLabel(Reservation $reservation): string
    {
        return $reservation->package?->city?->name
            ?? $reservation->package?->name
            ?? "Reserva #{$reservation->id}";
    }

    private function formatMoney(float $amount): string
    {
        return '$' . number_format($amount, 2, '.', ',');
    }

    private function relativeTime(?string $iso): string
    {
        if (!$iso) {
            return 'Reciente';
        }

        $date = strtotime($iso);
        if ($date === false) {
            return 'Reciente';
        }

        $diffMins = (int) floor((time() - $date) / 60);

        if ($diffMins < 1) {
            return 'Ahora';
        }
        if ($diffMins < 60) {
            return "Hace {$diffMins} min";
        }

        $diffHours = (int) floor($diffMins / 60);
        if ($diffHours < 24) {
            return "Hace {$diffHours} h";
        }

        $diffDays = (int) floor($diffHours / 24);
        if ($diffDays === 1) {
            return 'Ayer';
        }
        if ($diffDays < 7) {
            return "Hace {$diffDays} días";
        }

        return date('d/m/Y', $date);
    }

    private function departureLabel(?string $departureDate): string
    {
        if (!$departureDate) {
            return 'Salida próxima';
        }

        $days = (int) floor((strtotime($departureDate) - strtotime(today()->toDateString())) / 86400);

        if ($days === 0) {
            return 'Sale hoy';
        }
        if ($days === 1) {
            return 'Sale mañana';
        }
        if ($days > 0) {
            return "Sale en {$days} días";
        }
        if ($days === -1) {
            return 'Salió ayer';
        }

        return 'Salida hace ' . abs($days) . ' días';
    }
}
