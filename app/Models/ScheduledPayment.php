<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPayment extends Model
{
    public const STATUSES = ['pending', 'partial', 'paid', 'overdue', 'canceled'];

    protected $fillable = [
        'account_id',
        'sequence',
        'due_date',
        'amount',
        'amount_settled',
        'status',
        'observations',
        'is_plan_installment',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:4',
            'amount_settled' => 'decimal:4',
            'is_plan_installment' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
