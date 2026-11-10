<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierAccount extends Model
{
    public const STATES = ['pending', 'partial', 'paid', 'canceled'];

    protected $fillable = [
        'reservation_id',
        'total_amount',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:4',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function paidAmount(): float
    {
        return round((float) $this->supplierPayments()->sum('amount'), 4);
    }

    public function balanceDue(): float
    {
        return max(0, round((float) $this->total_amount - $this->paidAmount(), 4));
    }

    public function recalculateState(): void
    {
        if ($this->reservation?->state === 'canceled') {
            $this->state = 'canceled';
            $this->save();

            return;
        }

        $paid = $this->paidAmount();
        $total = (float) $this->total_amount;

        if ($total <= 0 && $paid <= 0) {
            $this->state = 'pending';
        } elseif ($paid >= $total && $total > 0) {
            $this->state = 'paid';
        } elseif ($paid > 0) {
            $this->state = 'partial';
        } else {
            $this->state = 'pending';
        }

        $this->save();
    }
}
