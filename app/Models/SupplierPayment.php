<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    public const PAYMENT_METHODS = ['cash', 'transfer', 'card', 'check', 'other'];

    protected $fillable = [
        'supplier_account_id',
        'amount',
        'paid_at',
        'payment_method',
        'confirmation',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'paid_at' => 'datetime',
        ];
    }

    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }
}
