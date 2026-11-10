<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceArchive extends Model
{
    public const TYPE_ACCOUNT = 'account';

    public const TYPE_SUPPLIER = 'supplier_account';

    public const TYPE_RECEIPT = 'payment_receipt';

    public const TYPE_FLIGHT = 'flight';

    public const TYPE_TRANSPORT = 'transport';

    public const TYPE_RESERVATION_PROFIT = 'reservation_profit';

    public const PROFIT_TYPES = [
        self::TYPE_FLIGHT,
        self::TYPE_TRANSPORT,
        self::TYPE_RESERVATION_PROFIT,
    ];

    protected $fillable = [
        'subject_type',
        'subject_id',
        'archived_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public static function archivedIds(string $subjectType): array
    {
        return static::query()
            ->where('subject_type', $subjectType)
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public static function isArchived(string $subjectType, int $subjectId): bool
    {
        return static::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->exists();
    }
}
