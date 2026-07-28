<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PartialPayment;
use Illuminate\Http\Request;

class PartialPaymentController extends Controller
{
    public function index(Account $account)
    {
        return response()->json(
            $account->partialPayments()->latest('paid_at')->get()
        );
    }

    public function store(Request $request, Account $account)
    {
        if ($account->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden registrar pagos en una cuenta cancelada.',
            ], 422);
        }

        $data = $request->validate([
            'amount'           => 'required|numeric|min:0.0001|max:9999999999999.9999',
            'transaction_type' => 'required|in:' . implode(',', PartialPayment::TRANSACTION_TYPES),
            'paid_at'          => 'required|date|before_or_equal:now',
            'payment_method'   => 'required|in:' . implode(',', PartialPayment::PAYMENT_METHODS),
            'sent_to_supplier' => 'nullable|string|max:255',
            'confirmation'     => 'nullable|string|max:45',
        ], [
            'paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.',
        ]);

        $this->validatePaymentAmount($account, $data);

        $payment = $account->partialPayments()->create($data);
        $account->recalculateState();

        return response()->json([
            'message' => 'Pago registrado correctamente.',
            'payment' => $payment,
            'account' => $account->fresh(),
        ], 201);
    }

    public function show(Account $account, PartialPayment $partialPayment)
    {
        $this->ensureBelongsToAccount($account, $partialPayment);

        return response()->json($partialPayment);
    }

    public function update(Request $request, Account $account, PartialPayment $partialPayment)
    {
        $this->ensureBelongsToAccount($account, $partialPayment);

        if ($account->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden editar pagos de una cuenta cancelada.',
            ], 422);
        }

        $data = $request->validate([
            'amount'           => 'required|numeric|min:0.0001|max:9999999999999.9999',
            'transaction_type' => 'required|in:' . implode(',', PartialPayment::TRANSACTION_TYPES),
            'paid_at'          => 'required|date|before_or_equal:now',
            'payment_method'   => 'required|in:' . implode(',', PartialPayment::PAYMENT_METHODS),
            'sent_to_supplier' => 'nullable|string|max:255',
            'confirmation'     => 'nullable|string|max:45',
        ], [
            'paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.',
        ]);

        $this->validatePaymentAmount($account, $data, $partialPayment);

        $partialPayment->update($data);
        $account->recalculateState();

        return response()->json([
            'message' => 'Pago actualizado correctamente.',
            'payment' => $partialPayment,
            'account' => $account->fresh(),
        ]);
    }

    public function destroy(Account $account, PartialPayment $partialPayment)
    {
        $this->ensureBelongsToAccount($account, $partialPayment);

        if ($account->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden eliminar pagos de una cuenta cancelada.',
            ], 422);
        }

        $partialPayment->delete();
        $account->recalculateState();

        return response()->json(['message' => 'Pago eliminado correctamente.']);
    }

    private function validatePaymentAmount(Account $account, array $data, ?PartialPayment $exclude = null): void
    {
        $paymentsQuery = $account->partialPayments()->where('transaction_type', 'payment');
        $refundsQuery = $account->partialPayments()->where('transaction_type', 'refund');

        if ($exclude) {
            $paymentsQuery->where('id', '!=', $exclude->id);
            $refundsQuery->where('id', '!=', $exclude->id);
        }

        $paidAmount = max(0, (float) $paymentsQuery->sum('amount') - (float) $refundsQuery->sum('amount'));

        if ($data['transaction_type'] === 'payment') {
            $newPaid = $paidAmount + (float) $data['amount'];

            if ($newPaid > (float) $account->total_amount + 0.0001) {
                abort(422, 'El pago supera el saldo pendiente de la cuenta.');
            }

            return;
        }

        if ((float) $data['amount'] > $paidAmount + 0.0001) {
            abort(422, 'El reembolso no puede superar el monto ya pagado.');
        }
    }

    private function ensureBelongsToAccount(Account $account, PartialPayment $partialPayment): void
    {
        if ((int) $partialPayment->account_id !== (int) $account->id) {
            abort(404, 'Pago no encontrado en esta cuenta.');
        }
    }
}
