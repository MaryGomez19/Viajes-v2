<?php

namespace App\Http\Controllers;

use App\Models\ImportantDate;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ImportantDateController extends Controller
{
    public function __construct(
        private GoogleCalendarService $googleCalendar
    ) {}

    public function index(Request $request)
    {
        $query = ImportantDate::query()
            ->with(['user:id,name,last_name,email,is_frequent_client'])
            ->latest('event_date');

        if ($search = trim((string) $request->query('search', ''))) {
            $escaped = addcslashes($search, '%_');
            $query->where(function ($q) use ($escaped) {
                $q->where('title', 'like', "%{$escaped}%")
                    ->orWhere('observation', 'like', "%{$escaped}%")
                    ->orWhereHas('user', function ($userQuery) use ($escaped) {
                        $userQuery->where('name', 'like', "%{$escaped}%")
                            ->orWhere('last_name', 'like', "%{$escaped}%")
                            ->orWhere('email', 'like', "%{$escaped}%");
                    });
            });
        }

        $this->applyListFilters($request, $query);

        if ($request->boolean('calendar')) {
            $year = (int) $request->query('year', now()->year);
            $month = (int) $request->query('month', now()->month);
            $items = (clone $query)->whereMonth('event_date', $month)->get();

            return response()->json([
                'year' => $year,
                'month' => $month,
                'items' => $items->map(fn (ImportantDate $d) => $this->mapDate($d))->values(),
            ]);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        return response()->json(
            $query->paginate($perPage)->through(fn (ImportantDate $d) => $this->mapDate($d))
        );
    }

    public function upcoming(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 10), 1), 50);
        $today = now()->startOfDay();

        $dates = ImportantDate::query()
            ->with(['user:id,name,last_name,email'])
            ->get()
            ->map(function (ImportantDate $date) use ($today) {
                $occurrence = $this->nextOccurrence($date->event_date, $today);

                return [
                    'model' => $date,
                    'next_occurrence' => $occurrence,
                    'days_until' => (int) $today->diffInDays($occurrence),
                ];
            })
            ->filter(fn (array $row) => $row['next_occurrence']->greaterThanOrEqualTo($today))
            ->sortBy(fn (array $row) => $row['next_occurrence']->timestamp)
            ->take($limit)
            ->values()
            ->map(function (array $row) {
                /** @var ImportantDate $date */
                $date = $row['model'];
                $mapped = $this->mapDate($date);
                $mapped['next_occurrence'] = $row['next_occurrence']->toDateString();
                $mapped['days_until'] = (int) $row['days_until'];

                return $mapped;
            });

        return response()->json($dates);
    }

    public function show(ImportantDate $importantDate)
    {
        $importantDate->load(['user:id,name,last_name,email', 'creator:id,name,last_name']);

        return response()->json($this->mapDate($importantDate));
    }

    public function store(Request $request)
    {
        $data = $this->validatedPayload($request);
        $data['created_by'] = $request->user()->id;
        $data['sync_status'] = 'pending';

        $date = ImportantDate::create($data);
        $date = $this->googleCalendar->syncImportantDate($date);

        return response()->json([
            'message' => 'Fecha importante registrada.',
            'important_date' => $this->mapDate($date->load(['user', 'creator'])),
        ], 201);
    }

    public function update(Request $request, ImportantDate $importantDate)
    {
        $data = $this->validatedPayload($request, $importantDate);
        $importantDate->update($data);

        if ($importantDate->sync_status !== 'synced') {
            $importantDate->sync_status = 'pending';
        }

        $importantDate = $this->googleCalendar->syncImportantDate($importantDate->fresh());

        return response()->json([
            'message' => 'Fecha importante actualizada.',
            'important_date' => $this->mapDate($importantDate->load(['user', 'creator'])),
        ]);
    }

    public function destroy(ImportantDate $importantDate)
    {
        $this->googleCalendar->deleteImportantDateEvent($importantDate);
        $importantDate->delete();

        return response()->json(['message' => 'Fecha importante eliminada.']);
    }

    public function sync(ImportantDate $importantDate)
    {
        $importantDate = $this->googleCalendar->syncImportantDate($importantDate->fresh());

        return response()->json([
            'message' => $importantDate->sync_status === 'synced'
                ? 'Sincronizado con Google Calendar.'
                : 'No se pudo sincronizar. Revisa el detalle del error.',
            'important_date' => $this->mapDate($importantDate->load(['user', 'creator'])),
        ]);
    }

    private function applyListFilters(Request $request, $query): void
    {
        if ($client = (string) $request->query('client', '')) {
            match ($client) {
                'with' => $query->whereNotNull('user_id'),
                'without' => $query->whereNull('user_id'),
                'frequent' => $query->whereHas(
                    'user',
                    fn ($userQuery) => $userQuery->where('is_frequent_client', true)
                ),
                default => null,
            };
        }

        if ($type = trim((string) $request->query('type', ''))) {
            if (in_array($type, ImportantDate::TYPES, true)) {
                $query->where('type', $type);
            } else {
                $escaped = addcslashes($type, '%_');
                $query->where('type', 'like', "%{$escaped}%");
            }
        }

        $syncStatus = $request->query('sync_status');
        if ($syncStatus === 'needs_sync') {
            $query->whereIn('sync_status', ['pending', 'error']);
        } elseif (is_string($syncStatus) && $syncStatus !== '') {
            $query->where('sync_status', $syncStatus);
        }

        if ($request->filled('event_month')) {
            $eventMonth = (int) $request->query('event_month');
            if ($eventMonth >= 1 && $eventMonth <= 12) {
                $query->whereMonth('event_date', $eventMonth);
            }
        }

        if ($request->filled('within_days')) {
            $days = min(max((int) $request->query('within_days'), 1), 365);
            $today = now()->startOfDay();
            $ids = (clone $query)
                ->get(['id', 'event_date'])
                ->filter(function (ImportantDate $date) use ($today, $days) {
                    $next = $this->nextOccurrence($date->event_date, $today);

                    return $next->greaterThanOrEqualTo($today)
                        && (int) $today->diffInDays($next) <= $days;
                })
                ->pluck('id');

            $query->whereIn('id', $ids->isEmpty() ? [-1] : $ids);
        }
    }

    private function validatedPayload(Request $request, ?ImportantDate $existing = null): array
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:32'],
            'event_date' => ['required', 'date'],
            'observation' => ['nullable', 'string', 'max:2000'],
            'reminder_preset' => ['required', Rule::in(ImportantDate::REMINDER_PRESETS)],
            'reminder_days_before' => [
                'nullable',
                'integer',
                'min:0',
                'max:365',
                Rule::requiredIf(fn () => $request->input('reminder_preset') === 'custom'),
            ],
            'reminder_time' => [
                'nullable',
                'date_format:H:i',
                Rule::requiredIf(fn () => $request->input('reminder_preset') === 'custom'),
            ],
        ]);

        if (($data['type'] ?? '') === 'informativo') {
            $data['user_id'] = null;
        }

        if (! empty($data['user_id'])) {
            $user = User::find($data['user_id']);
            if (! $user || ! $user->hasRole('cliente')) {
                abort(422, 'El usuario seleccionado no es un cliente válido.');
            }
        }

        if ($data['reminder_preset'] !== 'custom') {
            $data['reminder_days_before'] = null;
            $data['reminder_time'] = null;
        }

        if ($existing && $request->has('user_id') && $data['user_id'] === null) {
            $data['user_id'] = null;
        }

        return $data;
    }

    private function nextOccurrence(Carbon|string $eventDate, Carbon $from): Carbon
    {
        $base = Carbon::parse($eventDate);
        $month = (int) $base->month;
        $day = (int) $base->day;

        $candidate = Carbon::create($from->year, $month, 1, 0, 0, 0, $from->timezone);

        if ($month === 2 && $day === 29 && ! $candidate->isLeapYear()) {
            $day = 28;
        }

        $candidate = Carbon::create($from->year, $month, $day, 0, 0, 0, $from->timezone);

        if ($candidate->lessThan($from)) {
            $year = $from->year + 1;
            if ($month === 2 && $day === 29 && ! Carbon::create($year, 1, 1)->isLeapYear()) {
                $day = 28;
            }
            $candidate = Carbon::create($year, $month, $day, 0, 0, 0, $from->timezone);
        }

        return $candidate->startOfDay();
    }

    private function mapDate(ImportantDate $date): array
    {
        return [
            'id' => $date->id,
            'user_id' => $date->user_id,
            'title' => $date->title,
            'type' => $date->type,
            'event_date' => $date->event_date?->format('Y-m-d'),
            'observation' => $date->observation,
            'reminder_preset' => $date->reminder_preset,
            'reminder_days_before' => $date->reminder_days_before,
            'reminder_time' => $date->reminder_time
                ? substr((string) $date->reminder_time, 0, 5)
                : null,
            'google_calendar_event_id' => $date->google_calendar_event_id,
            'sync_status' => $date->sync_status,
            'sync_error' => $date->sync_error,
            'synced_at' => $date->synced_at?->toIso8601String(),
            'created_at' => $date->created_at?->toIso8601String(),
            'updated_at' => $date->updated_at?->toIso8601String(),
            'user' => $date->user ? [
                'id' => $date->user->id,
                'name' => $date->user->name,
                'last_name' => $date->user->last_name,
                'email' => $date->user->email,
                'is_frequent_client' => (bool) $date->user->is_frequent_client,
            ] : null,
            'creator' => $date->relationLoaded('creator') && $date->creator ? [
                'id' => $date->creator->id,
                'name' => $date->creator->name,
                'last_name' => $date->creator->last_name,
            ] : null,
        ];
    }
}
