<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'user_id',
        'city_id',
        'duration',
        'departure_date',
        'return_date',
        'stock',
        'quota_adults',
        'quota_juniors',
        'quota_children',
        'price_adult',
        'price_junior',
        'price_child',
        'package_total_cost',
        'platform_profit_percent',
        'info_price_adult',
        'info_price_junior',
        'info_price_child',
        'provider_cost',
        'client_cost',
        'supplier_payment',
        'bonus_discount',
        'bonus_discount_note',
        'image_path',
        'status',
        'is_private',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    // 📸 RELACIÓN NUEVA: Un paquete puede tener muchas imágenes para el carrusel
    public function images(): HasMany
    {
        return $this->hasMany(PackageImage::class, 'package_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
