<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Reservation;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PER_PAGE = 20;

    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = Account::with([
            'reservation.user',
            'reservation.package.city',
            'partialPayments',
            'scheduledPayments',
        ]);

        if ($request->filled('state')) {
            $request->validate(['state' => 'in:' . implode(',', Account::STATES)]);
            $query->where('state', $request->input('state'));
        }

        if ($request->filled('search')) {
            $search = addcslashes($request->input('search'), '%_');

            $query->whereHas('reservation.user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate($perPage)->withQueryString());
    }

    public function show(Account $account)
    {
        $account->load([
            'reservation.user',
            'reservation.package.city',
            'partialPayments',
            'scheduledPayments',
        ]);

        return response()->json([
            ...$account->toArray(),
            'paid_amount' => $account->paidAmount(),
            'balance_due' => $account->balanceDue(),
        ]);
    }

    public function showByReservation(Reservation $reservation)
    {
        $account = Account::where('reservation_id', $reservation->id)
            ->with([
                'reservation.user',
                'reservation.package.city',
                'partialPayments',
                'scheduledPayments',
            ])
            ->first();

        if (!$account) {
            return response()->json(['message' => 'Cuenta no encontrada para esta reserva.'], 404);
        }

        return response()->json([
            ...$account->toArray(),
            'paid_amount' => $account->paidAmount(),
            'balance_due' => $account->balanceDue(),
        ]);
    }

    public function showByReservationForClient(Request $request, Reservation $reservation)
    {
        $user = $request->user();

        if ((int) $reservation->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Reserva no encontrada'], 404);
        }

        $account = Account::where('reservation_id', $reservation->id)
            ->with(['partialPayments', 'scheduledPayments'])
            ->first();

        if (!$account) {
            return response()->json(['message' => 'Cuenta no encontrada para esta reserva.'], 404);
        }

        return response()->json([
            ...$account->toArray(),
            'paid_amount' => $account->paidAmount(),
            'balance_due' => $account->balanceDue(),
        ]);
    }

    public function update(Request $request, Account $account)
    {
        $data = $request->validate([
            'total_amount' => 'sometimes|numeric|min:0.0001|max:9999999999999.9999',
        ]);

        if (empty($data)) {
            return response()->json(['message' => 'No hay datos para actualizar.'], 422);
        }

        if (array_key_exists('total_amount', $data) && $data['total_amount'] < $account->paidAmount()) {
            return response()->json([
                'message' => 'El monto total no puede ser menor a lo ya pagado.',
            ], 422);
        }

        $account->update($data);
        $account->recalculateState();

        return response()->json([
            'message' => 'Cuenta actualizada correctamente.',
            'account' => $account->fresh(['partialPayments', 'scheduledPayments']),
        ]);
    }
}
