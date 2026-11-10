<?php

namespace App\Http\Controllers;

use App\Models\FinanceArchive;
use App\Models\Reservation;
use App\Models\SupplierAccount;
use App\Services\SupplierAccountService;
use App\Support\FinanceArchiveFilter;
use Illuminate\Http\Request;

class SupplierAccountController extends Controller
{
    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 20;

    public function index(Request $request)
    {
        Reservation::query()
            ->where('state', '!=', 'canceled')
            ->whereDoesntHave('supplierAccount')
            ->each(fn (Reservation $r) => SupplierAccountService::ensureForReservation($r));

        $perPage = max(1, min((int) $request->input('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        $query = SupplierAccount::with([
            'reservation.user',
            'reservation.package.city',
            'supplierPayments',
        ])->where('total_amount', '>', 0);

        if ($request->filled('state')) {
            $request->validate(['state' => 'in:'.implode(',', SupplierAccount::STATES)]);
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

        if ($request->boolean('trip_alert')) {
            SupplierAccountService::applyTripPaymentAlertScope($query);
        }

        FinanceArchiveFilter::apply(
            $query,
            FinanceArchive::TYPE_SUPPLIER,
            $request->input('visibility', 'active')
        );

        $paginator = $query->latest()->paginate($perPage)->withQueryString();
        $paginator->getCollection()->transform(function (SupplierAccount $account) {
            SupplierAccountService::syncTotals($account);

            return $this->formatAccount($account->fresh([
                'reservation.user',
                'reservation.package.city',
                'supplierPayments',
            ]));
        });

        return response()->json(array_merge($paginator->toArray(), [
            'trip_alert_count' => SupplierAccountService::countTripPaymentAlerts(),
        ]));
    }

    public function show(SupplierAccount $supplierAccount)
    {
        SupplierAccountService::syncTotals($supplierAccount);
        $supplierAccount->load([
            'reservation.user',
            'reservation.package.city',
            'reservation.flight',
            'reservation.transport',
            'reservation.hotel',
            'supplierPayments' => fn ($q) => $q->latest('paid_at'),
        ]);

        return response()->json($this->formatAccount($supplierAccount, true));
    }

    public function showByReservation(Reservation $reservation)
    {
        $account = SupplierAccountService::ensureForReservation($reservation);
        SupplierAccountService::syncTotals($account);

        return $this->show($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAccount(SupplierAccount $account, bool $withBreakdown = false): array
    {
        $reservation = $account->reservation;
        $payload = [
            ...$account->toArray(),
            'paid_amount' => $account->paidAmount(),
            'balance_due' => $account->balanceDue(),
            'supplier_payments' => $account->supplierPayments,
        ];

        if ($withBreakdown && $reservation) {
            $payload['breakdown_lines'] = SupplierAccountService::breakdownLines($reservation);
        }

        $payload['trip_payment_alert'] = SupplierAccountService::buildTripPaymentAlert(
            $reservation,
            $account->balanceDue(),
            (string) $account->state,
        );

        return $payload;
    }
}
