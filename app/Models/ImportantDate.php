<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportantDate extends Model
{
    public const TYPES = [
        'birthday',
        'anniversary',
        'special',
        'due_date',
        'informativo',
        'other',
    ];

    public const REMINDER_PRESETS = [
        'same_day',
        'one_day',
        'three_days',
        'one_week',
        'custom',
    ];

    public const SYNC_STATUSES = [
        'synced',
        'pending',
        'error',
    ];

    protected $fillable = [
        'user_id',
        'title',
        'type',
        'event_date',
        'observation',
        'reminder_preset',
        'reminder_days_before',
        'reminder_time',
        'google_calendar_event_id',
        'sync_status',
        'sync_error',
        'synced_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date:Y-m-d',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reminderMinutesBefore(): int
    {
        if ($this->reminder_preset === 'custom') {
            $days = max(0, (int) $this->reminder_days_before);

            return $days * 24 * 60;
        }

        return match ($this->reminder_preset) {
            'same_day' => 0,
            'one_day' => 24 * 60,
            'three_days' => 3 * 24 * 60,
            'one_week' => 7 * 24 * 60,
            default => 24 * 60,
        };
    }
}
