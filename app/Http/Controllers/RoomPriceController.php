<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\RoomPrice;
use Illuminate\Http\Request;

class RoomPriceController extends Controller
{
    /**
     * Listar todos los precios de un hotel específico.
     */
    public function index(Hotel $hotel)
    {
        return response()->json(
            $hotel->roomPrices()->orderBy('occupancy_type')->get()
        );
    }

    /**
     * Agregar un precio de habitación a un hotel.
     */
    public function store(Request $request, Hotel $hotel)
    {
        $data = $request->validate([
            'occupancy_type' => 'required|string|in:' . implode(',', RoomPrice::OCCUPANCY_TYPES),
            'nightly_rate'   => 'required|numeric|min:0.0001|max:9999999999999.9999',
            'total_rooms'    => 'required|integer|min:0|max:9999',
        ]);

        // Restricción de unicidad: un hotel no puede tener dos precios del mismo tipo
        $exists = $hotel->roomPrices()
            ->where('occupancy_type', $data['occupancy_type'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => "Este hotel ya tiene un precio registrado para el tipo '{$data['occupancy_type']}'.",
            ], 422);
        }

        $data['hotel_id'] = $hotel->id;

        $roomPrice = RoomPrice::create($data);

        $hotel->loadMissing('reservation');
        $hotel->reservation?->recalculateTotalAmount();

        return response()->json([
            'message'    => '¡Precio de habitación creado con éxito!',
            'room_price' => $roomPrice,
        ], 201);
    }

    /**
     * Mostrar un precio específico.
     */
    public function show(Hotel $hotel, RoomPrice $roomPrice)
    {
        // Verificar que el precio pertenece al hotel indicado
        if ($roomPrice->hotel_id !== $hotel->id) {
            return response()->json([
                'message' => 'Este precio no pertenece al hotel indicado.',
            ], 403);
        }

        return response()->json($roomPrice->load('hotel'));
    }

    /**
     * Actualizar un precio de habitación.
     */
    public function update(Request $request, Hotel $hotel, RoomPrice $roomPrice)
    {
        if ($roomPrice->hotel_id !== $hotel->id) {
            return response()->json([
                'message' => 'Este precio no pertenece al hotel indicado.',
            ], 403);
        }

        // No editar si la reserva del hotel está cancelada
        if ($hotel->reservation && $hotel->reservation->state === 'canceled') {
            return response()->json([
                'message' => 'No se puede editar precios de un hotel con reserva cancelada.',
            ], 403);
        }

        $data = $request->validate([
            'nightly_rate' => 'required|numeric|min:0.0001|max:9999999999999.9999',
            'total_rooms'  => 'required|integer|min:0|max:9999',
            // occupancy_type no se permite cambiar; es parte de la clave única
        ]);

        $roomPrice->update($data);

        $hotel->loadMissing('reservation');
        $hotel->reservation?->recalculateTotalAmount();

        return response()->json([
            'message'    => 'Precio de habitación actualizado correctamente.',
            'room_price' => $roomPrice,
        ]);
    }

    /**
     * Eliminar (soft delete) un precio de habitación.
     */
    public function destroy(Hotel $hotel, RoomPrice $roomPrice)
    {
        if ($roomPrice->hotel_id !== $hotel->id) {
            return response()->json([
                'message' => 'Este precio no pertenece al hotel indicado.',
            ], 403);
        }

        if ($hotel->reservation && $hotel->reservation->state === 'paid') {
            return response()->json([
                'message' => 'No se puede eliminar un precio con reserva pagada.',
            ], 422);
        }

        $roomPrice->delete();

        $hotel->loadMissing('reservation');
        $hotel->reservation?->recalculateTotalAmount();

        return response()->json([
            'message' => 'Precio de habitación eliminado correctamente.',
        ]);
    }
}
