<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsReservationServiceable;
use App\Models\Hotel;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    use AssertsReservationServiceable;
    /**
     * Listar todos los hoteles con sus precios por habitación.
     */
    public function index()
    {
        $hotels = Hotel::with([
            'reservation.user',
            'reservation.package',
            'roomPrices',
        ])->get();

        return response()->json($hotels);
    }

    /**
     * Crear un nuevo hotel.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'name'                 => 'required|string|max:50',
            'destination'          => 'nullable|string|max:50',
            'hgdl_key'             => 'nullable|string|max:20',
            'supplier'             => 'nullable|string|max:45',
            'booking_source'       => 'nullable|string|max:50',
            'check_in_out' => 'nullable|string|max:255',
            'client_cost' => 'nullable|numeric|min:0|max:9999999999999.9999',
            'provider_cost'        => 'nullable|numeric|min:0|max:9999999999999.9999',
            'observations'         => 'nullable|string|max:1000',
        ]);

        $this->assertReservationServiceable((int) $data['reservation_id']);

        $exists = Hotel::where('reservation_id', $data['reservation_id'])->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Esta reserva ya tiene un hotel asignado.',
            ], 422);
        }

        $hotel = Hotel::create($data);

        return response()->json([
            'message' => '¡Hotel creado con éxito!',
            'hotel'   => $hotel->load('roomPrices'),
        ], 201);
    }

    /**
     * Mostrar un hotel con sus precios.
     */
    public function show(Hotel $hotel)
    {
        return response()->json(
            $hotel->load(['reservation.user', 'reservation.package', 'roomPrices'])
        );
    }

    /**
     * Actualizar un hotel existente.
     */
    public function update(Request $request, Hotel $hotel)
    {
        // No editar si la reserva está cancelada
        if ($hotel->reservation && $hotel->reservation->state === 'canceled') {
            return response()->json([
                'message' => 'No se puede editar un hotel con reserva cancelada.',
            ], 403);
        }

        $data = $request->validate([
            'name'           => 'required|string|max:50',
            'destination'    => 'nullable|string|max:50',
            'hgdl_key'       => 'nullable|string|max:20',
            'supplier'       => 'nullable|string|max:45',
            'booking_source' => 'nullable|string|max:50',
            'check_in_out' => 'nullable|string|max:255',
            'client_cost' => 'nullable|numeric|min:0|max:9999999999999.9999',
            'provider_cost'  => 'nullable|numeric|min:0|max:9999999999999.9999',
            'observations'   => 'nullable|string|max:1000',
        ]);

        $hotel->update($data);

        return response()->json([
            'message' => 'Hotel actualizado correctamente.',
            'hotel'   => $hotel->load('roomPrices'),
        ]);
    }

    /**
     * Eliminar (soft delete) un hotel.
     */
    public function destroy(Hotel $hotel)
    {
        // No eliminar si la reserva está pagada
        if ($hotel->reservation && $hotel->reservation->state === 'paid') {
            return response()->json([
                'message' => 'No se puede eliminar un hotel con reserva pagada.',
            ], 422);
        }

        // No eliminar si aún tiene habitaciones disponibles activas
        if ($hotel->roomPrices()->where('total_rooms', '>', 0)->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar un hotel que aún tiene habitaciones disponibles.',
            ], 422);
        }

        $hotel->delete();

        return response()->json([
            'message' => 'Hotel eliminado correctamente.',
        ]);
    }

    public function suggestions(Request $request)
    {
        $search = addcslashes($request->input('q', ''), '%_');

        if (strlen($search) < 2) {
            return response()->json([]);
        }

        $hotels = Hotel::select('name', 'destination', 'supplier', 'hgdl_key', 'booking_source', 'provider_cost')
            ->where('name', 'like', "%{$search}%")
            ->groupBy('name', 'destination', 'supplier', 'hgdl_key', 'booking_source', 'provider_cost')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return response()->json($hotels);
    }
}
