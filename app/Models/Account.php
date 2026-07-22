<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    public const STATES = ['pending', 'partial', 'paid', 'overdue', 'canceled'];

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

    public function partialPayments(): HasMany
    {
        return $this->hasMany(PartialPayment::class);
    }

    public function scheduledPayments(): HasMany
    {
        return $this->hasMany(ScheduledPayment::class);
    }

    public function paidAmount(): float
    {
        $payments = (float) $this->partialPayments()
            ->where('transaction_type', 'payment')
            ->sum('amount');

        $refunds = (float) $this->partialPayments()
            ->where('transaction_type', 'refund')
            ->sum('amount');

        return max(0, $payments - $refunds);
    }

    public function balanceDue(): float
    {
        return max(0, (float) $this->total_amount - $this->paidAmount());
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

        if ($paid >= $total && $total > 0) {
            $this->state = 'paid';
        } elseif ($paid > 0) {
            $this->state = 'partial';
        } else {
            $hasOverdue = $this->scheduledPayments()
                ->where('status', 'pending')
                ->where('due_date', '<', now()->toDateString())
                ->exists();

            $this->state = $hasOverdue ? 'overdue' : 'pending';
        }

        $this->save();
    }
}
