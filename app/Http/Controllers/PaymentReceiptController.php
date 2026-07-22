<?php

namespace App\Http\Controllers;

use App\Models\PaymentReceipt;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaymentReceiptController extends Controller
{
    public function indexForClient(Request $request, Reservation $reservation)
    {
        $this->authorizeClientReservation($request, $reservation);

        return response()->json(
            $this->mapReceipts(
                $reservation->paymentReceipts()->latest()->get()
            )
        );
    }

    public function storeForClient(Request $request, Reservation $reservation)
    {
        $this->authorizeClientReservation($request, $reservation);

        if ($reservation->state === 'canceled') {
            return response()->json([
                'message' => 'No se pueden subir comprobantes en una reserva cancelada.',
            ], 422);
        }

        $data = $request->validate([
            'file'   => 'required|file|mimes:jpeg,jpg,png,webp,pdf|max:5120',
            'amount' => 'nullable|numeric|min:0.0001|max:9999999999999.9999',
            'notes'  => 'nullable|string|max:500',
        ]);

        $path = $request->file('file')->store(
            "reservations/{$reservation->id}/payment-receipts",
            'public'
        );

        $receipt = PaymentReceipt::create([
            'reservation_id' => $reservation->id,
            'user_id'        => $request->user()->id,
            'amount'         => $data['amount'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'original_name'  => $request->file('file')->getClientOriginalName(),
            'file_path'      => $path,
            'status'         => 'pending',
        ]);

        return response()->json([
            'message' => 'Comprobante enviado correctamente. La agencia lo revisará pronto.',
            'receipt' => $this->mapReceipt($receipt),
        ], 201);
    }

    public function destroyForClient(Request $request, Reservation $reservation, PaymentReceipt $paymentReceipt)
    {
        $this->authorizeClientReservation($request, $reservation);
        $this->ensureReceiptBelongsToReservation($reservation, $paymentReceipt);

        if ($paymentReceipt->status !== 'pending') {
            return response()->json([
                'message' => 'Solo puedes eliminar comprobantes pendientes de revisión.',
            ], 422);
        }

        Storage::disk('public')->delete($paymentReceipt->file_path);
        $paymentReceipt->delete();

        return response()->json(['message' => 'Comprobante eliminado correctamente.']);
    }

    public function indexForAdmin(Reservation $reservation)
    {
        return response()->json(
            $this->mapReceipts(
                $reservation->paymentReceipts()->with(['user', 'reservation.package'])->latest()->get()
            )
        );
    }

    public function indexAllForAdmin(Request $request)
    {
        $query = PaymentReceipt::with([
            'user:id,name,last_name,email,phone',
            'reservation:id,package_id,departure_date,user_id',
            'reservation.package:id,name',
        ])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = addcslashes($request->search, '%_');
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhere('original_name', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $this->mapReceipts($query->get())
        );
    }

    public function updateForAdmin(Request $request, PaymentReceipt $paymentReceipt)
    {
        $data = $request->validate([
            'status'      => 'required|in:' . implode(',', PaymentReceipt::STATUSES),
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $paymentReceipt->update($data);

        return response()->json([
            'message' => 'Comprobante actualizado correctamente.',
            'receipt' => $this->mapReceipt($paymentReceipt->fresh('user')),
        ]);
    }

    public function destroyForAdmin(PaymentReceipt $paymentReceipt)
    {
        Storage::disk('public')->delete($paymentReceipt->file_path);
        $paymentReceipt->delete();

        return response()->json(['message' => 'Comprobante eliminado correctamente.']);
    }

    private function authorizeClientReservation(Request $request, Reservation $reservation): void
    {
        if ((int) $reservation->user_id !== (int) $request->user()->id) {
            abort(404, 'Reserva no encontrada');
        }
    }

    private function ensureReceiptBelongsToReservation(
        Reservation $reservation,
        PaymentReceipt $paymentReceipt
    ): void {
        if ((int) $paymentReceipt->reservation_id !== (int) $reservation->id) {
            abort(404, 'Comprobante no encontrado.');
        }
    }

    private function mapReceipts($receipts): array
    {
        return $receipts->map(fn (PaymentReceipt $receipt) => $this->mapReceipt($receipt))->values()->all();
    }

    private function mapReceipt(PaymentReceipt $receipt): array
    {
        $data = [
            'id'            => $receipt->id,
            'reservation_id'=> $receipt->reservation_id,
            'amount'        => $receipt->amount,
            'notes'         => $receipt->notes,
            'original_name' => $receipt->original_name,
            'url'           => Storage::disk('public')->url($receipt->file_path),
            'status'        => $receipt->status,
            'admin_notes'   => $receipt->admin_notes,
            'created_at'    => $receipt->created_at,
            'user'          => $receipt->relationLoaded('user') && $receipt->user
                ? [
                    'id'        => $receipt->user->id,
                    'name'      => $receipt->user->name,
                    'last_name' => $receipt->user->last_name,
                    'email'     => $receipt->user->email ?? null,
                    'phone'     => $receipt->user->phone ?? null,
                ]
                : null,
        ];

        if ($receipt->relationLoaded('reservation') && $receipt->reservation) {
            $data['reservation'] = [
                'id'             => $receipt->reservation->id,
                'departure_date' => $receipt->reservation->departure_date,
                'package'        => $receipt->reservation->relationLoaded('package') && $receipt->reservation->package
                    ? ['name' => $receipt->reservation->package->name]
                    : null,
            ];
        }

        return $data;
    }
}
