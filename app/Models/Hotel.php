<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Hotel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'hotels';

    protected $fillable = [
        'reservation_id',
        'name',
        'destination',
        'hgdl_key',
        'supplier',
        'booking_source',
        'check_in_out',
        'provider_cost',
        'client_cost',
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

    public function roomPrices()
    {
        return $this->hasMany(RoomPrice::class);
    }

    public function scopeByDestination($query, string $destination)
    {
        return $query->where('destination', $destination);
    }

    public function scopeBySupplier($query, string $supplier)
    {
        return $query->where('supplier', $supplier);
    }

    public function getProfitAttribute()
    {
        if ($this->client_cost === null || $this->provider_cost === null) {
            return null;
        }
        return round($this->client_cost - $this->provider_cost, 4);
    }
}
