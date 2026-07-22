<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartialPayment extends Model
{
    public const TRANSACTION_TYPES = ['payment', 'refund'];
    public const PAYMENT_METHODS = ['cash', 'transfer', 'card', 'check', 'other'];

    protected $fillable = [
        'account_id',
        'amount',
        'transaction_type',
        'paid_at',
        'payment_method',
        'sent_to_supplier',
        'confirmation',
    ];

    protected function casts(): array
    {
        return [
            'amount'  => 'decimal:4',
            'paid_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
