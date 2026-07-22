<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Package;
use App\Models\Passenger;
use App\Models\Hotel;
use App\Models\RoomPrice;
use App\Models\Flight;
use App\Models\Transport;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class QuickReservationController extends Controller
{
    public function store(Request $request)
    {
        $isAdmin = $request->user()->hasRole('admin');

        $data = $request->validate([
            // Cliente dueño de la cuenta
            'user_id' => ['nullable', 'exists:users,id', Rule::requiredIf(fn() => $isAdmin && !$request->filled('new_client'))],

            // Cliente nuevo — opcional, alternativa a user_id
            'new_client' => 'nullable|array',
            'new_client.name' => 'required_with:new_client|string|max:100',
            'new_client.last_name' => 'required_with:new_client|string|max:100',
            'new_client.email' => 'nullable|email|max:150|unique:users,email',
            'new_client.phone' => 'nullable|string|max:20',

            // Datos del "paquete privado" (invisible en la web)
            'city_id' => 'required|exists:cities,id',
            'name' => 'nullable|string|max:255',
            'duration' => 'required|string|max:255',
            'departure_date' => 'required|date',
            'return_date' => 'required|date|after_or_equal:departure_date',
            'price_adult' => 'required|numeric|min:0',
            'price_junior' => 'required|numeric|min:0',
            'price_child' => 'required|numeric|min:0',

            // Datos de la reserva
            'reference_person' => 'nullable|string|max:45',
            'reservation_date' => 'required|date',
            'adults' => 'nullable|integer|min:0',
            'juniors' => 'nullable|integer|min:0',
            'children' => 'nullable|integer|min:0',
            'state' => 'required|in:pending,confirmed,canceled,finished,paid',
            'observations' => 'nullable|string|max:500',

            // Pasajeros
            'passengers' => 'required|array|min:1',
            'passengers.*.name' => 'required|string|max:50',
            'passengers.*.last_name' => 'required|string|max:50',
            'passengers.*.birth_date' => 'required|date|before:today',
            'passengers.*.nationality' => 'required|string|max:45',
            'passengers.*.type' => 'required|in:adult,junior,child',

            // Hotel — opcional
            'hotel' => 'nullable|array',
            'hotel.name' => 'required_with:hotel|string|max:50',
            'hotel.destination' => 'nullable|string|max:50',
            'hotel.hgdl_key' => 'nullable|string|max:20',
            'hotel.supplier' => 'nullable|string|max:45',
            'hotel.booking_source' => 'nullable|string|max:50',
            'hotel.check_in_out' => 'nullable|string|max:255',
            'hotel.provider_cost' => 'nullable|numeric|min:0|max:9999999999999.9999',
            'hotel.client_cost' => 'nullable|numeric|min:0|max:9999999999999.9999',
            'hotel.observations' => 'nullable|string|max:1000',
            'hotel.room_prices' => 'required_with:hotel|array|min:1',
            'hotel.room_prices.*.occupancy_type' => 'required|in:single,double,triple,quadruple,suite',
            'hotel.room_prices.*.nightly_rate' => 'required|numeric|min:0.0001|max:9999999999999.9999',
            'hotel.room_prices.*.total_rooms' => 'required|integer|min:0|max:9999',

            // Vuelo — opcional
            'flight' => 'nullable|array',
            'flight.airline_name' => 'required_with:flight|string|max:50',
            'flight.destination' => 'required_with:flight|string|max:50',
            'flight.flight_schedule' => 'required_with:flight|string|max:255',
            'flight.hgdl_key' => 'required_with:flight|string|max:20',
            'flight.booking_source' => 'nullable|string|max:50',
            'flight.provider_cost' => 'required_with:flight|numeric|min:0.0001',
            'flight.client_cost' => 'nullable|numeric|min:0.0001',
            'flight.observations' => 'nullable|string|max:500',

            // Transporte — opcional
            'transport' => 'nullable|array',
            'transport.company' => 'required_with:transport|string|max:50',
            'transport.hgdl_key' => 'required_with:transport|string|max:20',
            'transport.destination' => 'required_with:transport|string|max:50',
            'transport.horary' => 'required_with:transport|string|max:255',
            'transport.supplier' => 'required_with:transport|string|max:50',
            'transport.provider_cost' => 'required_with:transport|numeric|min:0.0001',
            'transport.client_cost' => 'nullable|numeric|min:0.0001',
            'transport.booking_source' => 'nullable|string|max:50',
            'transport.observations' => 'nullable|string|max:500',
        ]);

        if (empty($data['user_id']) && empty($data['new_client'])) {
            abort(422, 'Debes seleccionar un cliente existente o proporcionar los datos de uno nuevo.');
        }

        if (!empty($data['user_id'])) {
            $cliente = User::with('role')->find($data['user_id']);
            if (!$cliente || !$cliente->hasRole('cliente') || $cliente->state !== 'active') {
                abort(422, 'Debes seleccionar un cliente activo válido.');
            }
        }

        $data['user_id'] = $isAdmin ? ($data['user_id'] ?? null) : $request->user()->id;

        $data = $this->normalizePassengers($data);
        $this->validatePassengerTypes($data);

        if (count($data['passengers']) !== $data['reserved_seats']) {
            abort(422, 'El número de pasajeros no coincide con el total de lugares (adultos + juniors + niños).');
        }

        return DB::transaction(function () use ($data, $request) {
            // 0. Cliente nuevo (si aplica) — dentro de la misma transacción
            if (empty($data['user_id']) && !empty($data['new_client'])) {
                $nc = $data['new_client'];
                $rolCliente = Role::findByKey(Role::CLIENTE);

                if (!$rolCliente) {
                    abort(500, 'Rol de cliente no configurado.');
                }

                $nuevoCliente = User::create([
                    'name' => trim($nc['name']),
                    'last_name' => trim($nc['last_name']),
                    'email' => !empty($nc['email']) ? trim($nc['email']) : null,
                    'phone' => !empty($nc['phone']) ? trim($nc['phone']) : null,
                    'password' => Hash::make(Str::random(10)),
                    'state' => 'active',
                    'role_id' => $rolCliente->id,
                ]);
                $data['user_id'] = $nuevoCliente->id;
            }

            // 1. Paquete privado, oculto en la web
            $package = Package::create([
                'name' => $data['name'] ?? 'Reserva privada - ' . ($data['reference_person'] ?? now()->format('d/m/Y')),
                'description' => null,
                'user_id' => $request->user()->id,
                'city_id' => $data['city_id'],
                'duration' => $data['duration'],
                'departure_date' => $data['departure_date'],
                'return_date' => $data['return_date'],
                'stock' => $data['reserved_seats'],
                'price_adult' => $data['price_adult'],
                'price_junior' => $data['price_junior'],
                'price_child' => $data['price_child'],
                'status' => 'inactive',
                'is_private' => true,
            ]);

            // 2. Reservación
            $reservation = Reservation::create([
                'user_id' => $data['user_id'],
                'package_id' => $package->id,
                'reference_person' => $data['reference_person'] ?? null,
                'reservation_date' => $data['reservation_date'],
                'departure_date' => $data['departure_date'],
                'return_date' => $data['return_date'],
                'reserved_seats' => $data['reserved_seats'],
                'adults' => $data['adults'],
                'juniors' => $data['juniors'],
                'children' => $data['children'],
                'unit_price_adult' => $data['price_adult'],
                'unit_price_junior' => $data['price_junior'],
                'unit_price_child' => $data['price_child'],
                'total_amount' => ($data['adults'] * $data['price_adult']) +
                    ($data['juniors'] * $data['price_junior']) +
                    ($data['children'] * $data['price_child']),
                'state' => $data['state'],
                'observations' => $data['observations'] ?? null,
            ]);

            $package->decrement('stock', $data['reserved_seats']);

            // 3. Pasajeros
            foreach ($data['passengers'] as $p) {
                Passenger::create([
                    'reservation_id' => $reservation->id,
                    'name' => $p['name'],
                    'last_name' => $p['last_name'],
                    'birth_date' => $p['birth_date'],
                    'nationality' => $p['nationality'],
                    'type' => $p['type'],
                ]);
            }

            // 4. Hotel (opcional)
            if (!empty($data['hotel'])) {
                $hotel = Hotel::create([
                    'reservation_id' => $reservation->id,
                    'name' => $data['hotel']['name'],
                    'destination' => $data['hotel']['destination'] ?? null,
                    'hgdl_key' => $data['hotel']['hgdl_key'] ?? null,
                    'supplier' => $data['hotel']['supplier'] ?? null,
                    'booking_source' => $data['hotel']['booking_source'] ?? null,
                    'check_in_out' => $data['hotel']['check_in_out'] ?? null,
                    'provider_cost' => $data['hotel']['provider_cost'] ?? null,
                    'client_cost' => $data['hotel']['client_cost'] ?? null,
                    'observations' => $data['hotel']['observations'] ?? null,
                ]);

                foreach ($data['hotel']['room_prices'] as $rp) {
                    RoomPrice::create([
                        'hotel_id' => $hotel->id,
                        'occupancy_type' => $rp['occupancy_type'],
                        'nightly_rate' => $rp['nightly_rate'],
                        'total_rooms' => $rp['total_rooms'],
                    ]);
                }
            }

            // 5. Vuelo (opcional)
            if (!empty($data['flight'])) {
                Flight::create([
                    'reservation_id' => $reservation->id,
                    'airline_name' => $data['flight']['airline_name'],
                    'destination' => $data['flight']['destination'],
                    'flight_schedule' => $data['flight']['flight_schedule'],
                    'hgdl_key' => $data['flight']['hgdl_key'],
                    'booking_source' => $data['flight']['booking_source'] ?? null,
                    'provider_cost' => $data['flight']['provider_cost'],
                    'client_cost' => $data['flight']['client_cost'] ?? null,
                    'observations' => $data['flight']['observations'] ?? null,
                ]);
            }

            // 6. Transporte (opcional)
            if (!empty($data['transport'])) {
                Transport::create([
                    'reservation_id' => $reservation->id,
                    'company' => $data['transport']['company'],
                    'hgdl_key' => $data['transport']['hgdl_key'],
                    'destination' => $data['transport']['destination'],
                    'horary' => $data['transport']['horary'],
                    'supplier' => $data['transport']['supplier'],
                    'provider_cost' => $data['transport']['provider_cost'],
                    'client_cost' => $data['transport']['client_cost'] ?? null,
                    'booking_source' => $data['transport']['booking_source'] ?? null,
                    'observations' => $data['transport']['observations'] ?? null,
                ]);
            }

            return response()->json([
                'message' => '¡Reserva privada creada con éxito!',
                'reservation' => $reservation->load([
                    'package.city',
                    'user',
                    'passengers',
                    'hotel.roomPrices',
                    'flight',
                    'transport',
                ]),
            ], 201);
        });
    }

    /**
     * Normaliza cantidades por tipo de pasajero y calcula reserved_seats total.
     * Nota: duplicado de ReservationController@normalizePassengers.
     * Si te sobra tiempo al final, extraer a un Trait compartido.
     */
    private function normalizePassengers(array $data): array
    {
        $adults = (int) ($data['adults'] ?? 0);
        $juniors = (int) ($data['juniors'] ?? 0);
        $children = (int) ($data['children'] ?? 0);
        $total = $adults + $juniors + $children;

        if ($total < 1) {
            abort(422, 'Debes reservar al menos un lugar.');
        }

        $data['adults'] = $adults;
        $data['juniors'] = $juniors;
        $data['children'] = $children;
        $data['reserved_seats'] = $total;

        return $data;
    }

    private function validatePassengerTypes(array $data): void
    {
        $typeCounts = array_count_values(array_column($data['passengers'], 'type'));

        if (($typeCounts['adult'] ?? 0) !== $data['adults']) {
            abort(422, 'La cantidad de pasajeros tipo adulto no coincide con el campo adults.');
        }

        if (($typeCounts['junior'] ?? 0) !== $data['juniors']) {
            abort(422, 'La cantidad de pasajeros tipo junior no coincide con el campo juniors.');
        }

        if (($typeCounts['child'] ?? 0) !== $data['children']) {
            abort(422, 'La cantidad de pasajeros tipo niño no coincide con el campo children.');
        }
    }
}
