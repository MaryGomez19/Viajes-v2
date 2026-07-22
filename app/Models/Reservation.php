<?php

namespace App\Models;

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

    public function paymentReceipts()
    {
        return $this->hasMany(PaymentReceipt::class);
    }
}
