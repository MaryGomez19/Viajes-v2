<?php

namespace App\Http\Controllers;

use App\Models\Transport;
use Illuminate\Http\Request;

class TransportController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $transports = Transport::with(['reservation.user', 'reservation.package'])->get();
        return response()->json($transports);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'company' => 'required|string|max:50',
            'hgdl_key' => 'required|string|max:20',
            'destination' => 'required|string|max:50',
            'horary' => 'required|string|max:255',
            'supplier' => 'required|string|max:50',
            'provider_cost' => 'required|numeric|min:0.0001',
            'client_cost' => 'nullable|numeric|min:0.0001',
            'booking_source' => 'nullable|string|max:50',
            'observations' => 'nullable|string|max:500',
        ]);

        $exists = Transport::where(
            'reservation_id',
            $data['reservation_id']
        )->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Esta reserva ya tiene información de transporte.'
            ], 422);
        }

        $transport = Transport::create($data);
        return response()->json([
            'message' => '¡Información de transporte creada con éxito!',
            'transport' => $transport
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Transport $transport)
    {
        return response()->json($transport->load('reservation.package'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Transport $transport)
    {
        if ($transport->reservation && $transport->reservation->state === 'canceled') {
            return response()->json([
                'message' => 'No se puede editar el transporte de una reserva cancelada.'
            ], 403);
        }

        $data = $request->validate([
            'company' => 'required|string|max:50',
            'hgdl_key' => 'required|string|max:20',
            'destination' => 'required|string|max:50',
            'horary' => 'required|string|max:255',
            'supplier' => 'required|string|max:50',
            'provider_cost' => 'required|numeric|min:0',
            'client_cost' => 'nullable|numeric|min:0.0001',
            'booking_source' => 'nullable|string|max:50',
            'observations' => 'nullable|string|max:500',
        ]);

        $transport->update($data);

        return response()->json([
            'message' => 'Transporte actualizado correctamente',
            'transport' => $transport
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Transport $transport)
    {
        if ($transport->reservation && $transport->reservation->state === 'paid') {
            return response()->json([
                'message' => 'No se puede eliminar un transporte con reserva pagada.'
            ], 422);
        }

        $transport->delete();
        return response()->json(['message' => 'Transporte eliminado correctamente']);
    }
}
