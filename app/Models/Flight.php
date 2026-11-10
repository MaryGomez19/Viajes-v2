<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Flight extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reservation_id',
        'airline_name',
        'destination',
        'flight_schedule',
        'hgdl_key',
        'booking_source',
        'provider_cost',
        'client_cost',
        'supplier_payment',
        'bonus_discount',
        'bonus_discount_note',
        'observations',
    ];
    protected $appends = ['profit'];

    protected $casts = [
        'provider_cost' => 'decimal:4',
        'client_cost' => 'decimal:4',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function getProfitAttribute()
    {
        if ($this->client_cost === null || $this->provider_cost === null) {
            return null;
        }
        return round($this->client_cost - $this->provider_cost, 4);
    }
}
