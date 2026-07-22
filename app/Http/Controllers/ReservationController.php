<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PER_PAGE = 20;
    private const STATES = ['pending', 'confirmed', 'canceled', 'finished', 'paid'];


    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = Reservation::with(['user', 'package.user', 'package.city', 'flight', 'hotel', 'transport', 'documents']);

        //filtro de archivado 
        if ($request->input('archived') === 'all') {
            $query->withTrashed();
        } elseif ($request->input('archived') === 'only') {
            $query->onlyTrashed();
        }

        if ($request->boolean('without_flight')) {
            $query->whereDoesntHave('flight');
        }

        if ($request->boolean('without_transport')) {
            $query->whereDoesntHave('transport');
        }

        if ($request->boolean('without_hotel')) {
            $query->whereDoesntHave('hotel');
        }

        if ($request->filled('state')) {
            $request->validate(['state' => 'in:' . implode(',', self::STATES)]);
            $query->where('state', $request->input('state'));
        }

        // Filtro por tipo de paquete (público/privado)
        if ($request->filled('package_status')) {
            $request->validate(['package_status' => 'in:active,inactive']);
            $query->whereHas('package', function ($q) use ($request) {
                $q->where('status', $request->input('package_status'));
            });
        }

        if ($request->filled('search')) {
            $search = addcslashes($request->input('search'), '%_');

            $query->where(function ($q) use ($search) {
                $q->where('reference_person', 'like', "%{$search}%")
                    ->orWhereHas('package', function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $reservations = $query->latest()->paginate($perPage)->withQueryString();

        return response()->json($reservations);
    }

    public function misReservas(Request $request)
    {
        $user = $request->user();

        $reservas = Reservation::where('user_id', $user->id)
            ->with(['package.city', 'flight', 'transport', 'hotel', 'passengers'])
            ->latest()
            ->get();

        return response()->json($reservas);
    }

    public function misReservaDetalle(Request $request, $id)
    {
        $user = $request->user();

        $reserva = Reservation::where('user_id', $user->id)
            ->where('id', $id)
            ->with(['package.city', 'flight', 'passengers'])
            ->first();

        if (!$reserva) {
            return response()->json(['message' => 'Reserva no encontrada'], 404);
        }

        // Solo devolvemos los campos que el cliente puede ver
        return response()->json([
            'id' => $reserva->id,
            'reservation_date' => $reserva->reservation_date,
            'departure_date' => $reserva->departure_date,
            'return_date' => $reserva->return_date,
            'reserved_seats' => $reserva->reserved_seats,
            'adults' => $reserva->adults,
            'juniors' => $reserva->juniors,
            'children' => $reserva->children,
            'unit_price_adult' => $reserva->unit_price_adult,
            'unit_price_junior' => $reserva->unit_price_junior,
            'unit_price_child' => $reserva->unit_price_child,
            'total_amount' => $reserva->total_amount,
            'state' => $reserva->state,
            'passengers' => $reserva->passengers,
            'package' => $reserva->package,
            'flight' => $reserva->flight ? [
                'airline_name' => $reserva->flight->airline_name,
                'destination' => $reserva->flight->destination,
                'flight_schedule' => $reserva->flight->flight_schedule,
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $isAdmin = $request->user()->hasRole('admin');

        $rules = [
            'user_id' => [
                Rule::requiredIf($isAdmin),
                'nullable',
                'exists:users,id',
            ],
            'package_id' => 'required|exists:packages,id',
            'reference_person' => 'nullable|string|max:45',
            'reservation_date' => 'required|date',
            'departure_date' => 'required|date|after_or_equal:reservation_date',
            'return_date' => 'required|date|after_or_equal:departure_date',
            'adults' => 'nullable|integer|min:0',
            'juniors' => 'nullable|integer|min:0',
            'children' => 'nullable|integer|min:0',
            'reserved_seats' => 'nullable|integer|min:1',
            'observations' => 'nullable|string|max:500',
        ];

        if ($isAdmin) {
            $rules['state'] = 'required|in:' . implode(',', self::STATES);
        }

        $data = $request->validate($rules);

        if ($isAdmin) {
            $cliente = User::with('role')->find($data['user_id']);
            if (!$cliente || !$cliente->hasRole('cliente') || $cliente->state !== 'active') {
                abort(422, 'Debes seleccionar un cliente activo válido.');
            }
        } else {
            $data['state'] = 'pending';
            $data['user_id'] = $request->user()->id;
        }

        return DB::transaction(function () use ($data, $isAdmin) {
            $package = Package::lockForUpdate()->findOrFail($data['package_id']);

            if (!$isAdmin) {
                if ($package->status !== 'active' || $package->is_private) {
                    abort(422, 'Este paquete no está disponible para reservar.');
                }
            }

            $data = $this->normalizePassengers($data);
            $this->applyPricing($package, $data);
            $seats = $data['reserved_seats'];
            $countsAgainstStock = $data['state'] !== 'canceled';

            if ($countsAgainstStock && $package->stock < $seats) {
                abort(422, 'No hay suficientes lugares en stock.');
            }

            $reservation = Reservation::create($data);

            if ($countsAgainstStock && $seats > 0) {
                $package->decrement('stock', $seats);
            }

            return response()->json([
                'message' => '¡Reserva creada con éxito!',
                'reservation' => $reservation->load(['package.city', 'user', 'account']),
            ], 201);
        });
    }

    public function show($id)
    {
        $reservation = Reservation::withTrashed()
            ->with([
                'user',
                'package.user',
                'package.city',
                'passengers',
                'flight',
                'hotel',
                'transport',
                'flightWithTrashed',
                'hotelWithTrashed',
                'transportWithTrashed',
            ])
            ->findOrFail($id);

        $reservation->is_archived = $reservation->trashed();

        return response()->json($reservation);
    }

    public function update(Request $request, Reservation $reservation)
    {
        if ($reservation->state === 'canceled') {
            return response()->json(['message' => 'Una reserva cancelada no se puede editar'], 403);
        }

        $data = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'reference_person' => 'nullable|string|max:45',
            'reservation_date' => 'required|date',
            'departure_date' => 'required|date|after_or_equal:reservation_date',
            'return_date' => 'required|date|after_or_equal:departure_date',
            'adults' => 'nullable|integer|min:0',
            'juniors' => 'nullable|integer|min:0',
            'children' => 'nullable|integer|min:0',
            'reserved_seats' => 'nullable|integer|min:1',
            'state' => 'required|in:' . implode(',', self::STATES),
            'observations' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($data, $reservation) {
            $isCanceling = $data['state'] === 'canceled';
            $isChangingPackage = (int) $data['package_id'] !== (int) $reservation->package_id;

            $data = $this->normalizePassengers($data, $reservation);
            $oldSeats = $reservation->reserved_seats ?? 0;
            $newSeats = $data['reserved_seats'];

            if ($isChangingPackage) {
                $packageIds = [$reservation->package_id, $data['package_id']];
                sort($packageIds);
                $locked = Package::whereIn('id', $packageIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $oldPackage = $locked[$reservation->package_id];
                $newPackage = $locked[$data['package_id']];
                $this->applyPricing($newPackage, $data);

                if (!$isCanceling && $newPackage->stock < $newSeats) {
                    abort(422, 'No hay suficientes lugares disponibles en el paquete seleccionado.');
                }

                $oldPackage->increment('stock', $oldSeats);

                if (!$isCanceling) {
                    $newPackage->decrement('stock', $newSeats);
                }
            } else {
                $package = Package::lockForUpdate()->findOrFail($data['package_id']);
                $this->applyPricing($package, $data);

                if ($isCanceling) {
                    $package->increment('stock', $oldSeats);
                } else {
                    $diff = $newSeats - $oldSeats;
                    if ($diff > 0 && $package->stock < $diff) {
                        abort(422, 'No hay suficientes lugares disponibles.');
                    }
                    if ($diff !== 0) {
                        $package->decrement('stock', $diff);
                    }
                }
            }

            $reservation->update($data);

            return response()->json([
                'message' => '¡Reserva actualizada con éxito!',
                'reservation' => $reservation->fresh(['package.city', 'package.user']),
            ]);
        });
    }

    public function porCliente(Request $request, $userId)
    {
        // Validamos que el cliente exista y no sea, por ejemplo, un admin viendo su propio expediente por error
        $cliente = \App\Models\User::findOrFail($userId);

        $reservas = Reservation::withTrashed()
            ->where('user_id', $userId)
            ->with([
                'package.city',
                'flightWithTrashed',
                'hotelWithTrashed',
                'transportWithTrashed',
            ])
            ->latest()
            ->get()
            ->map(function ($reserva) {
                $reserva->is_archived = $reserva->trashed();
                return $reserva;
            });

        return response()->json([
            'cliente' => [
                'id' => $cliente->id,
                'name' => $cliente->name,
                'last_name' => $cliente->last_name,
                'email' => $cliente->email,
                'phone' => $cliente->phone,
            ],
            'reservas' => $reservas,
            'resumen' => [
                'total' => $reservas->count(),
                'activas' => $reservas->where('is_archived', false)->count(),
                'archivadas' => $reservas->where('is_archived', true)->count(),
            ],
        ]);
    }

    public function clientesArchivados(Request $request)
    {
        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = \App\Models\User::query()
            ->whereHas('reservations', function ($q) {
                $q->onlyTrashed();
            })
            ->withCount([
                'reservations as archived_count' => function ($q) {
                    $q->onlyTrashed();
                },
                'reservations as total_count' => function ($q) {
                    $q->withTrashed();
                },
            ])
            ->with([
                'reservations' => function ($q) {
                    $q->onlyTrashed()->latest()->limit(1); // última reserva archivada, para mostrar fecha
                },
            ]);

        // Buscador por nombre/apellido/email
        if ($request->filled('search')) {
            $search = addcslashes($request->input('search'), '%_');

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filtro: rango de fecha en que se archivó
        if ($request->filled('archived_from')) {
            $query->whereHas('reservations', function ($q) use ($request) {
                $q->onlyTrashed()->where('deleted_at', '>=', $request->input('archived_from'));
            });
        }

        if ($request->filled('archived_to')) {
            $query->whereHas('reservations', function ($q) use ($request) {
                $q->onlyTrashed()->where('deleted_at', '<=', $request->input('archived_to'));
            });
        }

        // Filtro: destino (ciudad del paquete)
        if ($request->filled('city_id')) {
            $query->whereHas('reservations', function ($q) use ($request) {
                $q->onlyTrashed()->whereHas('package', function ($q2) use ($request) {
                    $q2->where('city_id', $request->input('city_id'));
                });
            });
        }

        $clientes = $query->orderByDesc('archived_count')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($clientes);
    }

    public function destroy(Reservation $reservation)
    {
        DB::transaction(function () use ($reservation) {
            if ($reservation->state !== 'canceled') {
                $package = Package::lockForUpdate()->find($reservation->package_id);

                if ($package && ($reservation->reserved_seats ?? 0) > 0) {
                    $package->increment('stock', $reservation->reserved_seats);
                }
            }

            $reservation->delete();
        });

        return response()->json(['message' => 'Reserva archivada correctamente'], 200);
    }

    //para restaurar, por si se archivo por error
    public function restore(Request $request, $id)
    {
        $reservation = DB::transaction(function () use ($id) {
            $reservation = Reservation::onlyTrashed()->findOrFail($id);

            if ($reservation->state !== 'canceled') {
                $package = Package::lockForUpdate()->findOrFail($reservation->package_id);
                $seats = $reservation->reserved_seats ?? 0;

                if ($seats > 0 && $package->stock < $seats) {
                    abort(422, 'No hay stock suficiente para restaurar esta reserva.');
                }

                if ($seats > 0) {
                    $package->decrement('stock', $seats);
                }
            }

            $reservation->restore();

            if ($reservation->account && $reservation->state !== 'canceled') {
                $reservation->account->recalculateState();
            }

            return $reservation;
        });

        return response()->json([
            'message' => 'Reserva restaurada correctamente',
            'reservation' => $reservation,
        ]);
    }

    //borrado físico permanente, solo para admin
    public function forceDestroy(Request $request, $id)
    {
        if (!$request->user()->hasRole('admin')) {
            abort(403, 'No autorizado');
        }

        $reservation = Reservation::onlyTrashed()->findOrFail($id);
        $reservation->forceDelete();

        return response()->json(['message' => 'Reserva eliminada permanentemente'], 200);
    }

    /**
     * Normaliza cantidades por tipo de pasajero y calcula reserved_seats total.
     */
    private function normalizePassengers(array $data, ?Reservation $existing = null): array
    {
        $hasBreakdown = array_key_exists('adults', $data)
            || array_key_exists('juniors', $data)
            || array_key_exists('children', $data);

        if ($hasBreakdown) {
            $adults = (int) ($data['adults'] ?? 0);
            $juniors = (int) ($data['juniors'] ?? 0);
            $children = (int) ($data['children'] ?? 0);
        } elseif (array_key_exists('reserved_seats', $data) && $data['reserved_seats'] !== null) {
            $adults = (int) $data['reserved_seats'];
            $juniors = 0;
            $children = 0;
        } elseif ($existing) {
            $adults = (int) ($existing->adults ?? 0);
            $juniors = (int) ($existing->juniors ?? 0);
            $children = (int) ($existing->children ?? 0);
        } else {
            $adults = 0;
            $juniors = 0;
            $children = 0;
        }

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

    /**
     * Guarda snapshot de precios del paquete y calcula el total de la reserva.
     */
    private function applyPricing(Package $package, array &$data): void
    {
        $data['unit_price_adult'] = $package->price_adult;
        $data['unit_price_junior'] = $package->price_junior;
        $data['unit_price_child'] = $package->price_child;
        $data['total_amount'] =
            ($data['adults'] * $package->price_adult) +
            ($data['juniors'] * $package->price_junior) +
            ($data['children'] * $package->price_child);
    }
}
