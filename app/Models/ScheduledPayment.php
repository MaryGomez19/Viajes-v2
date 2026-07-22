<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPayment extends Model
{
    public const STATUSES = ['pending', 'paid', 'overdue', 'canceled'];

    protected $fillable = [
        'account_id',
        'due_date',
        'amount',
        'status',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount'   => 'decimal:4',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
