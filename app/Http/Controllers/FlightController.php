<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsReservationServiceable;
use App\Models\Flight;
use Illuminate\Http\Request;

class FlightController extends Controller
{
    use AssertsReservationServiceable;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $flight = Flight::with(['reservation.user', 'reservation.package'])->get();
        return response()->json($flight);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'airline_name' => 'required|string|max:50',
            'destination' => 'required|string|max:50',
            'flight_schedule' => 'required|string|max:255',
            'hgdl_key' => 'required|string|max:20',
            'booking_source' => 'nullable|string|max:50',
            'client_cost' => 'nullable|numeric|min:0.0001',
            'provider_cost' => 'required|numeric|min:0.0001',
            'observations' => 'nullable|string|max:500',
        ]);

        $this->assertReservationServiceable((int) $data['reservation_id']);

        $exists = Flight::where(
            'reservation_id',
            $data['reservation_id']
        )->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Esta reserva ya tiene información de vuelo.'
            ], 422);
        }

        $flight = Flight::create($data);
        $flight->reservation?->recalculateTotalAmount();

        return response()->json([
            'message' => '¡Información de vuelo creada con éxito!',
            'flight' => $flight
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Flight $flight)
    {
        return response()->json($flight->load('reservation.package'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Flight $flight)
    {

        if ($flight->reservation && $flight->reservation->state === 'canceled') {
            return response()->json([
                'message' => 'No se puede editar el vuelo de una reserva cancelada.'
            ], 403);
        }

        $data = $request->validate([
            'airline_name' => 'required|string|max:50',
            'destination' => 'required|string|max:50',
            'flight_schedule' => 'required|string|max:255',
            'hgdl_key' => 'required|string|max:20',
            'booking_source' => 'nullable|string|max:50',
            'client_cost' => 'nullable|numeric|min:0.0001',
            'provider_cost' => 'required|numeric|min:0',
            'observations' => 'nullable|string|max:500',
        ]);

        $flight->update($data);
        $flight->reservation?->recalculateTotalAmount();

        return response()->json([
            'message' => 'Vuelo actualizado correctamente',
            'flight' => $flight
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Flight $flight)
    {
        if ($flight->reservation && $flight->reservation->state === 'paid') {
            return response()->json([
                'message' => 'No se puede eliminar un vuelo con reserva pagada.'
            ], 422);
        }

        $reservation = $flight->reservation;
        $flight->delete();
        $reservation?->recalculateTotalAmount();

        return response()->json(['message' => 'Vuelo eliminado correctamente']);
    }
}
