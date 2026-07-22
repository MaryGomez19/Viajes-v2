<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ScheduledPayment;
use Illuminate\Http\Request;

class ScheduledPaymentController extends Controller
{
    public function index(Account $account)
    {
        return response()->json(
            $account->scheduledPayments()->orderBy('due_date')->get()
        );
    }

    public function store(Request $request, Account $account)
    {
        if ($account->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden agregar abonos programados a una cuenta cancelada.',
            ], 422);
        }

        $data = $request->validate([
            'due_date'     => 'required|date',
            'amount'       => 'required|numeric|min:0.0001|max:9999999999999.9999',
            'observations' => 'nullable|string|max:1000',
        ]);

        $scheduled = $account->scheduledPayments()->create([
            ...$data,
            'status' => 'pending',
        ]);

        $account->recalculateState();

        return response()->json([
            'message'   => 'Abono programado creado correctamente.',
            'scheduled' => $scheduled,
        ], 201);
    }

    public function show(Account $account, ScheduledPayment $scheduledPayment)
    {
        $this->ensureBelongsToAccount($account, $scheduledPayment);

        return response()->json($scheduledPayment);
    }

    public function update(Request $request, Account $account, ScheduledPayment $scheduledPayment)
    {
        $this->ensureBelongsToAccount($account, $scheduledPayment);

        if ($account->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden editar abonos de una cuenta cancelada.',
            ], 422);
        }

        $data = $request->validate([
            'due_date'     => 'required|date',
            'amount'       => 'required|numeric|min:0.0001|max:9999999999999.9999',
            'status'       => 'required|in:pending,canceled',
            'observations' => 'nullable|string|max:1000',
        ]);

        $scheduledPayment->update($data);
        $account->recalculateState();

        return response()->json([
            'message'   => 'Abono programado actualizado correctamente.',
            'scheduled' => $scheduledPayment,
        ]);
    }

    public function destroy(Account $account, ScheduledPayment $scheduledPayment)
    {
        $this->ensureBelongsToAccount($account, $scheduledPayment);

        if ($account->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden eliminar abonos de una cuenta cancelada.',
            ], 422);
        }

        $scheduledPayment->delete();
        $account->recalculateState();

        return response()->json(['message' => 'Abono programado eliminado correctamente.']);
    }

    private function ensureBelongsToAccount(Account $account, ScheduledPayment $scheduledPayment): void
    {
        if ((int) $scheduledPayment->account_id !== (int) $account->id) {
            abort(404, 'Abono no encontrado en esta cuenta.');
        }
    }
}
