<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Reservation;
use App\Models\ScheduledPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TourPaymentPlanService
{
    public const MIN_INSTALLMENTS = 1;

    public const MAX_INSTALLMENTS = 15;

    /**
     * @return array<int, float>
     */
    public static function equalSplit(float $total, int $parts): array
    {
        if ($parts < 1) {
            return [];
        }

        $totalCents = (int) round($total * 100);
        $base = intdiv($totalCents, $parts);
        $remainder = $totalCents - ($base * $parts);
        $result = [];

        for ($i = 0; $i < $parts; $i++) {
            $cents = $base + ($i < $remainder ? 1 : 0);
            $result[] = round($cents / 100, 2);
        }

        return $result;
    }

    public static function assertCanCreatePlan(int $installments): void
    {
        if ($installments < self::MIN_INSTALLMENTS || $installments > self::MAX_INSTALLMENTS) {
            throw ValidationException::withMessages([
                'payment_plan_installments' => [
                    'El plan de pagos debe tener entre '.self::MIN_INSTALLMENTS.' y '.self::MAX_INSTALLMENTS.' parcialidades.',
                ],
            ]);
        }
    }

    public static function createForTourReservation(
        Reservation $reservation,
        int $installments,
    ): void {
        self::assertCanCreatePlan($installments);

        $account = $reservation->account;
        if (! $account) {
            return;
        }

        if ($account->paidAmount() > 0) {
            throw ValidationException::withMessages([
                'payment_plan_installments' => [
                    'No se puede crear el plan de pagos cuando ya hay abonos registrados.',
                ],
            ]);
        }

        $total = (float) $reservation->total_amount;
        if ($total <= 0) {
            throw ValidationException::withMessages([
                'payment_plan_installments' => [
                    'El precio total del viaje debe ser mayor a cero para configurar un plan de pagos.',
                ],
            ]);
        }

        DB::transaction(function () use ($account, $reservation, $installments, $total) {
            $account->scheduledPayments()
                ->where('is_plan_installment', true)
                ->delete();

            $account->update([
                'total_amount' => $total,
                'payment_plan_installments' => $installments,
                'payment_plan_managed' => true,
            ]);

            self::seedInstallments($account, $reservation, $installments, $total);
            self::redistribute($account->fresh());
        });
    }

    public static function seedInstallments(
        Account $account,
        Reservation $reservation,
        int $installments,
        float $total,
    ): void {
        $amounts = self::equalSplit($total, $installments);
        $start = Carbon::parse($reservation->reservation_date ?? $reservation->departure_date ?? now());

        foreach ($amounts as $index => $amount) {
            ScheduledPayment::create([
                'account_id' => $account->id,
                'sequence' => $index + 1,
                'due_date' => $start->copy()->addMonths($index)->toDateString(),
                'amount' => $amount,
                'amount_settled' => 0,
                'status' => 'pending',
                'is_plan_installment' => true,
                'observations' => 'Parcialidad '.($index + 1).' de '.$installments,
            ]);
        }
    }

    public static function syncIfManaged(Account $account): void
    {
        if (! $account->payment_plan_managed) {
            return;
        }

        DB::transaction(fn () => self::redistribute($account->fresh()));
    }

    public static function redistribute(Account $account): void
    {
        $installments = $account->scheduledPayments()
            ->where('is_plan_installment', true)
            ->orderBy('sequence')
            ->get();

        $count = $installments->count();
        if ($count === 0) {
            $account->recalculateState();

            return;
        }

        $total = round((float) $account->total_amount, 2);
        $totalPaid = round($account->paidAmount(), 2);
        $balance = round(max(0, $total - $totalPaid), 2);

        if ($totalPaid <= 0) {
            $amounts = self::equalSplit($total, $count);
            foreach ($installments as $index => $installment) {
                $installment->update([
                    'amount' => $amounts[$index] ?? 0,
                    'amount_settled' => 0,
                    'status' => 'pending',
                ]);
            }
            self::refreshOverdueFlags($account);
            $account->recalculateState();

            return;
        }

        /** @var ScheduledPayment $first */
        $first = $installments->first();
        $rest = $installments->slice(1);
        $firstCap = (float) $first->amount;

        if ($firstCap <= 0 && $count > 0) {
            $amounts = self::equalSplit($total, $count);
            foreach ($installments as $index => $installment) {
                $installment->update([
                    'amount' => $amounts[$index] ?? 0,
                    'amount_settled' => 0,
                    'status' => 'pending',
                ]);
            }
            self::refreshOverdueFlags($account);
            $account->recalculateState();

            return;
        }

        if ($totalPaid >= $firstCap) {
            $first->update([
                'amount_settled' => $firstCap,
                'status' => 'paid',
            ]);
        } else {
            $first->update([
                'amount_settled' => $totalPaid,
                'status' => 'partial',
            ]);
        }

        $splitTargets = $rest->values();
        $splitCount = $splitTargets->count();

        if ($splitCount === 0) {
            self::refreshOverdueFlags($account);
            $account->recalculateState();

            return;
        }

        if ($balance <= 0) {
            foreach ($splitTargets as $installment) {
                $installment->update([
                    'amount' => 0,
                    'amount_settled' => 0,
                    'status' => 'paid',
                ]);
            }
        } else {
            $amounts = self::equalSplit($balance, $splitCount);
            foreach ($splitTargets as $index => $installment) {
                $installment->update([
                    'amount' => $amounts[$index] ?? 0,
                    'amount_settled' => 0,
                    'status' => 'pending',
                ]);
            }
        }

        self::refreshOverdueFlags($account);
        $account->recalculateState();
    }

    private static function refreshOverdueFlags(Account $account): void
    {
        $today = now()->toDateString();

        $account->scheduledPayments()
            ->where('is_plan_installment', true)
            ->whereIn('status', ['pending', 'partial'])
            ->where('due_date', '<', $today)
            ->update(['status' => 'overdue']);

        $account->scheduledPayments()
            ->where('is_plan_installment', true)
            ->where('status', 'overdue')
            ->where('due_date', '>=', $today)
            ->update(['status' => 'pending']);
    }
}
