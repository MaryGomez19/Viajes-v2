<?php

namespace App\Models;

use App\Services\SupplierAccountService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'package_id',
        'reference_person',
        'reservation_date',
        'departure_date',
        'return_date',
        'reserved_seats',
        'adults',
        'juniors',
        'children',
        'unit_price_adult',
        'unit_price_junior',
        'unit_price_child',
        'total_amount',
        'state',
        'observations',
        'booking_channel',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'departure_date' => 'date',
            'return_date' => 'date',
            'unit_price_adult' => 'decimal:2',
            'unit_price_junior' => 'decimal:2',
            'unit_price_child' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function totalPassengers(): int
    {
        return ($this->adults ?? 0) + ($this->juniors ?? 0) + ($this->children ?? 0);
    }

    public function packageSubtotal(): float
    {
        return round(
            ($this->adults * $this->unit_price_adult) +
            ($this->juniors * $this->unit_price_junior) +
            ($this->children * $this->unit_price_child),
            2
        );
    }

    public function hotelRoomExtrasTotal(): float
    {
        $this->loadMissing('hotel.roomPrices');

        if (!$this->hotel) {
            return 0.0;
        }

        $total = $this->hotel->roomPrices->sum(function ($roomPrice) {
            $rate = (float) $roomPrice->nightly_rate;
            if ($rate <= 0) {
                return 0.0;
            }

            $rooms = (int) $roomPrice->total_rooms;
            $rooms = $rooms > 0 ? $rooms : 1;

            return $rate * $rooms;
        });

        return round((float) $total, 2);
    }

    public function recalculateTotalAmount(): void
    {
        $this->loadMissing(['flight', 'transport', 'hotel.roomPrices']);

        $total = $this->packageSubtotal()
            + (float) ($this->flight?->client_cost ?? 0)
            + (float) ($this->transport?->client_cost ?? 0)
            + $this->hotelRoomExtrasTotal();

        $total = round($total, 2);

        if ((float) $this->total_amount !== $total) {
            $this->update(['total_amount' => $total]);
        }
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function flight()
    {
        return $this->hasOne(Flight::class);
    }
    public function transport()
    {
        return $this->hasOne(Transport::class);
    }
    public function hotel()
    {
        return $this->hasOne(Hotel::class);
    }

    public function passengers()
    {
        return $this->hasMany(Passenger::class);
    }

    //Para ver el detalle completo, archivado o no
    public function flightWithTrashed()
    {
        return $this->hasOne(Flight::class)->withTrashed();
    }

    public function hotelWithTrashed()
    {
        return $this->hasOne(Hotel::class)->withTrashed();
    }

    public function transportWithTrashed()
    {
        return $this->hasOne(Transport::class)->withTrashed();
    }

    protected static function booted(): void
    {
        static::created(function (Reservation $reservation) {
            if ($reservation->state !== 'canceled') {
                $account = Account::create([
                    'reservation_id' => $reservation->id,
                    'total_amount'   => $reservation->total_amount ?? 0,
                    'state'          => 'pending',
                ]);
                $account->recalculateState();
            }

            if ($reservation->state !== 'canceled') {
                SupplierAccountService::ensureForReservation($reservation);
            }
        });

        static::updated(function (Reservation $reservation) {
            if (!$reservation->account) {
                return;
            }

            if ($reservation->wasChanged('total_amount')) {
                $reservation->account->update([
                    'total_amount' => $reservation->total_amount ?? 0,
                ]);
                $reservation->account->recalculateState();
            }

            if ($reservation->wasChanged('state') && $reservation->state === 'canceled') {
                $reservation->account->update(['state' => 'canceled']);
                $reservation->supplierAccount?->update(['state' => 'canceled']);
            }
        });

        static::deleting(function (Reservation $reservation) {
            $reservation->flight?->delete();
            $reservation->hotel?->delete();
            $reservation->transport?->delete();
        });

        static::restoring(function (Reservation $reservation) {
            $reservation->flight()->withTrashed()->first()?->restore();
            $reservation->hotel()->withTrashed()->first()?->restore();
            $reservation->transport()->withTrashed()->first()?->restore();
        });
    }

    public function documents()
    {
        return $this->hasMany(ReservationDocument::class);
    }

    public function account()
    {
        return $this->hasOne(Account::class);
    }

    public function supplierAccount()
    {
        return $this->hasOne(SupplierAccount::class);
    }

    public function paymentReceipts()
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    /**
     * Permite a admins resolver reservas archivadas en rutas tipo /reservations/{reservation}/...
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field = $field ?: $this->getRouteKeyName();

        $query = static::query()->where($field, $value);

        $user = auth()->user();
        if ($user && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            $query->withTrashed();
        }

        return $query->first();
    }
}
