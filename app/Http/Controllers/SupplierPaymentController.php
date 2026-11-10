<?php

namespace App\Http\Controllers;

use App\Models\SupplierAccount;
use App\Services\SupplierAccountService;
use App\Models\SupplierPayment;
use Illuminate\Http\Request;

class SupplierPaymentController extends Controller
{
    public function index(SupplierAccount $supplierAccount)
    {
        return response()->json(
            $supplierAccount->supplierPayments()->latest('paid_at')->get()
        );
    }

    public function store(Request $request, SupplierAccount $supplierAccount)
    {
        if ($supplierAccount->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden registrar pagos en una cuenta cancelada.',
            ], 422);
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.0001|max:9999999999999.9999',
            'paid_at' => 'required|date|before_or_equal:now',
            'payment_method' => 'required|in:'.implode(',', SupplierPayment::PAYMENT_METHODS),
            'confirmation' => 'nullable|string|max:45',
            'observations' => 'nullable|string|max:500',
        ], [
            'paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.',
        ]);

        $this->validatePaymentAmount($supplierAccount, $data);

        $payment = $supplierAccount->supplierPayments()->create($data);
        $supplierAccount->recalculateState();

        return response()->json([
            'message' => 'Pago a proveedor registrado correctamente.',
            'payment' => $payment,
            'account' => $this->freshAccountPayload($supplierAccount),
        ], 201);
    }

    public function update(Request $request, SupplierAccount $supplierAccount, SupplierPayment $supplierPayment)
    {
        $this->ensureBelongsToAccount($supplierAccount, $supplierPayment);

        if ($supplierAccount->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden editar pagos de una cuenta cancelada.',
            ], 422);
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.0001|max:9999999999999.9999',
            'paid_at' => 'required|date|before_or_equal:now',
            'payment_method' => 'required|in:'.implode(',', SupplierPayment::PAYMENT_METHODS),
            'confirmation' => 'nullable|string|max:45',
            'observations' => 'nullable|string|max:500',
        ], [
            'paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.',
        ]);

        $this->validatePaymentAmount($supplierAccount, $data, $supplierPayment);

        $supplierPayment->update($data);
        $supplierAccount->recalculateState();

        return response()->json([
            'message' => 'Pago a proveedor actualizado correctamente.',
            'payment' => $supplierPayment,
            'account' => $this->freshAccountPayload($supplierAccount),
        ]);
    }

    public function destroy(SupplierAccount $supplierAccount, SupplierPayment $supplierPayment)
    {
        $this->ensureBelongsToAccount($supplierAccount, $supplierPayment);

        if ($supplierAccount->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden eliminar pagos de una cuenta cancelada.',
            ], 422);
        }

        $supplierPayment->delete();
        $supplierAccount->recalculateState();

        return response()->json(['message' => 'Pago eliminado correctamente.']);
    }

    private function validatePaymentAmount(
        SupplierAccount $account,
        array $data,
        ?SupplierPayment $exclude = null,
    ): void {
        $paidQuery = $account->supplierPayments();
        if ($exclude) {
            $paidQuery->where('id', '!=', $exclude->id);
        }

        $paidAmount = (float) $paidQuery->sum('amount');
        $newPaid = $paidAmount + (float) $data['amount'];

        if ($newPaid > (float) $account->total_amount + 0.0001) {
            abort(422, 'El pago supera el saldo pendiente con proveedores.');
        }
    }

    private function ensureBelongsToAccount(
        SupplierAccount $account,
        SupplierPayment $payment,
    ): void {
        if ((int) $payment->supplier_account_id !== (int) $account->id) {
            abort(404, 'Pago no encontrado en esta cuenta.');
        }
    }

    private function freshAccountPayload(SupplierAccount $account): array
    {
        $account = $account->fresh(['supplierPayments', 'reservation.user', 'reservation.package.city']);

        $balance = $account->balanceDue();

        return [
            ...$account->toArray(),
            'paid_amount' => $account->paidAmount(),
            'balance_due' => $balance,
            'supplier_payments' => $account->supplierPayments,
            'trip_payment_alert' => SupplierAccountService::buildTripPaymentAlert(
                $account->reservation,
                $balance,
                (string) $account->state,
            ),
        ];
    }
}
