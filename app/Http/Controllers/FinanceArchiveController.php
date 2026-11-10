<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FinanceArchive;
use App\Models\Flight;
use App\Models\PaymentReceipt;
use App\Models\Reservation;
use App\Models\SupplierAccount;
use App\Models\Transport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceArchiveController extends Controller
{
    public function ids(Request $request)
    {
        $types = $request->query('types');
        $query = FinanceArchive::query();

        if (is_string($types) && $types !== '') {
            $list = array_filter(array_map('trim', explode(',', $types)));
            if ($list !== []) {
                $query->whereIn('subject_type', $list);
            }
        }

        $grouped = $query->get(['subject_type', 'subject_id'])
            ->groupBy('subject_type')
            ->map(fn ($items) => $items->pluck('subject_id')->map(fn ($id) => (int) $id)->values()->all())
            ->all();

        return response()->json($grouped);
    }

    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $query = FinanceArchive::query()
            ->with('archiver:id,name,last_name')
            ->latest('archived_at');

        $module = (string) $request->query('module', '');
        if ($module === 'accounts') {
            $query->where('subject_type', FinanceArchive::TYPE_ACCOUNT);
        } elseif ($module === 'supplier') {
            $query->where('subject_type', FinanceArchive::TYPE_SUPPLIER);
        } elseif ($module === 'receipts') {
            $query->where('subject_type', FinanceArchive::TYPE_RECEIPT);
        } elseif ($module === 'profits') {
            $query->whereIn('subject_type', FinanceArchive::PROFIT_TYPES);
        } elseif ($request->filled('subject_type')) {
            $query->where('subject_type', $request->query('subject_type'));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $escaped = addcslashes($search, '%_\\');
            $matchingIds = $this->searchMatchingArchiveIds($escaped, $module);
            $query->whereIn('id', $matchingIds ?: [-1]);
        }

        return response()->json(
            $query->paginate($perPage)->through(fn (FinanceArchive $row) => $this->mapArchiveRow($row))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject_type' => [
                'required',
                'string',
                Rule::in([
                    FinanceArchive::TYPE_ACCOUNT,
                    FinanceArchive::TYPE_SUPPLIER,
                    FinanceArchive::TYPE_RECEIPT,
                    FinanceArchive::TYPE_FLIGHT,
                    FinanceArchive::TYPE_TRANSPORT,
                    FinanceArchive::TYPE_RESERVATION_PROFIT,
                ]),
            ],
            'subject_id' => 'required|integer|min:1',
        ]);

        $this->assertSubjectExists($data['subject_type'], (int) $data['subject_id']);

        if (FinanceArchive::isArchived($data['subject_type'], (int) $data['subject_id'])) {
            return response()->json(['message' => 'Este registro ya está archivado.'], 422);
        }

        $archive = FinanceArchive::create([
            'subject_type' => $data['subject_type'],
            'subject_id' => (int) $data['subject_id'],
            'archived_by' => $request->user()->id,
            'archived_at' => now(),
        ]);

        return response()->json([
            'message' => 'Registro archivado.',
            'archive' => $this->mapArchiveRow($archive->load('archiver:id,name,last_name')),
        ], 201);
    }

    public function destroy(FinanceArchive $financeArchive)
    {
        $financeArchive->delete();

        return response()->json(['message' => 'Registro restaurado a finanzas activas.']);
    }

    private function assertSubjectExists(string $type, int $id): void
    {
        $exists = match ($type) {
            FinanceArchive::TYPE_ACCOUNT => Account::whereKey($id)->exists(),
            FinanceArchive::TYPE_SUPPLIER => SupplierAccount::whereKey($id)->exists(),
            FinanceArchive::TYPE_RECEIPT => PaymentReceipt::whereKey($id)->exists(),
            FinanceArchive::TYPE_FLIGHT => Flight::whereKey($id)->exists(),
            FinanceArchive::TYPE_TRANSPORT => Transport::whereKey($id)->exists(),
            FinanceArchive::TYPE_RESERVATION_PROFIT => Reservation::whereKey($id)->exists(),
            default => false,
        };

        if (! $exists) {
            abort(404, 'Registro de finanzas no encontrado.');
        }
    }

    private function mapArchiveRow(FinanceArchive $archive): array
    {
        $summary = $this->resolveSummary($archive->subject_type, (int) $archive->subject_id);

        return [
            'id' => $archive->id,
            'subject_type' => $archive->subject_type,
            'subject_id' => (int) $archive->subject_id,
            'module' => $this->moduleKey($archive->subject_type),
            'module_label' => $this->moduleLabel($archive->subject_type),
            'title' => $summary['title'],
            'subtitle' => $summary['subtitle'],
            'meta' => $summary['meta'],
            'archived_at' => $archive->archived_at?->toIso8601String(),
            'archiver' => $archive->archiver ? [
                'id' => $archive->archiver->id,
                'name' => $archive->archiver->name,
                'last_name' => $archive->archiver->last_name,
            ] : null,
        ];
    }

    private function moduleKey(string $subjectType): string
    {
        return match ($subjectType) {
            FinanceArchive::TYPE_ACCOUNT => 'accounts',
            FinanceArchive::TYPE_SUPPLIER => 'supplier',
            FinanceArchive::TYPE_RECEIPT => 'receipts',
            default => 'profits',
        };
    }

    private function moduleLabel(string $subjectType): string
    {
        return match ($subjectType) {
            FinanceArchive::TYPE_ACCOUNT => 'Cuenta por cobrar',
            FinanceArchive::TYPE_SUPPLIER => 'Pago a proveedor',
            FinanceArchive::TYPE_RECEIPT => 'Comprobante de cliente',
            FinanceArchive::TYPE_FLIGHT => 'Ganancia · Vuelo',
            FinanceArchive::TYPE_TRANSPORT => 'Ganancia · Transporte',
            FinanceArchive::TYPE_RESERVATION_PROFIT => 'Ganancia · Paquete/Tour',
            default => 'Finanzas',
        };
    }

    private function resolveSummary(string $type, int $id): array
    {
        return match ($type) {
            FinanceArchive::TYPE_ACCOUNT => $this->summarizeAccount($id),
            FinanceArchive::TYPE_SUPPLIER => $this->summarizeSupplier($id),
            FinanceArchive::TYPE_RECEIPT => $this->summarizeReceipt($id),
            FinanceArchive::TYPE_FLIGHT => $this->summarizeFlight($id),
            FinanceArchive::TYPE_TRANSPORT => $this->summarizeTransport($id),
            FinanceArchive::TYPE_RESERVATION_PROFIT => $this->summarizeReservationProfit($id),
            default => ['title' => "#{$id}", 'subtitle' => null, 'meta' => []],
        };
    }

    private function summarizeAccount(int $id): array
    {
        $account = Account::with(['reservation.user', 'reservation.package'])->find($id);
        if (! $account) {
            return ['title' => "Cuenta #{$id}", 'subtitle' => null, 'meta' => []];
        }

        $user = $account->reservation?->user;
        $name = $user ? trim("{$user->name} {$user->last_name}") : '—';

        return [
            'title' => "Cuenta #{$account->id} · {$name}",
            'subtitle' => $account->reservation?->package?->name,
            'meta' => [
                'reservation_id' => $account->reservation_id,
                'state' => $account->state,
            ],
        ];
    }

    private function summarizeSupplier(int $id): array
    {
        $account = SupplierAccount::with(['reservation.user', 'reservation.package'])->find($id);
        if (! $account) {
            return ['title' => "Proveedor #{$id}", 'subtitle' => null, 'meta' => []];
        }

        $user = $account->reservation?->user;
        $name = $user ? trim("{$user->name} {$user->last_name}") : '—';

        return [
            'title' => "Cuenta proveedor #{$account->id} · {$name}",
            'subtitle' => $account->reservation?->package?->name,
            'meta' => [
                'reservation_id' => $account->reservation_id,
                'state' => $account->state,
            ],
        ];
    }

    private function summarizeReceipt(int $id): array
    {
        $receipt = PaymentReceipt::with(['user', 'reservation.package'])->find($id);
        if (! $receipt) {
            return ['title' => "Comprobante #{$id}", 'subtitle' => null, 'meta' => []];
        }

        $name = $receipt->user
            ? trim("{$receipt->user->name} {$receipt->user->last_name}")
            : 'Cliente';

        return [
            'title' => "Comprobante #{$receipt->id} · {$name}",
            'subtitle' => $receipt->reservation?->package?->name,
            'meta' => [
                'reservation_id' => $receipt->reservation_id,
                'status' => $receipt->status,
            ],
        ];
    }

    private function summarizeFlight(int $id): array
    {
        $flight = Flight::with(['reservation.user'])->find($id);
        if (! $flight) {
            return ['title' => "Vuelo #{$id}", 'subtitle' => null, 'meta' => []];
        }

        return [
            'title' => ($flight->airline_name ?? 'Vuelo').' · #'.$flight->id,
            'subtitle' => $flight->reservation?->user?->name,
            'meta' => ['reservation_id' => $flight->reservation_id],
        ];
    }

    private function summarizeTransport(int $id): array
    {
        $transport = Transport::with(['reservation.user'])->find($id);
        if (! $transport) {
            return ['title' => "Transporte #{$id}", 'subtitle' => null, 'meta' => []];
        }

        return [
            'title' => ($transport->company ?? 'Transporte').' · #'.$transport->id,
            'subtitle' => $transport->reservation?->user?->name,
            'meta' => ['reservation_id' => $transport->reservation_id],
        ];
    }

    private function summarizeReservationProfit(int $id): array
    {
        $reservation = Reservation::with(['user', 'package'])->find($id);
        if (! $reservation) {
            return ['title' => "Reserva #{$id}", 'subtitle' => null, 'meta' => []];
        }

        $label = $reservation->booking_channel === 'tour' ? 'Tour' : 'Paquete';

        return [
            'title' => "{$label} · Reserva #{$reservation->id}",
            'subtitle' => $reservation->package?->name,
            'meta' => [
                'client' => $reservation->user?->name,
                'booking_channel' => $reservation->booking_channel,
            ],
        ];
    }

    private function searchMatchingArchiveIds(string $search, string $module): array
    {
        $types = match ($module) {
            'accounts' => [FinanceArchive::TYPE_ACCOUNT],
            'supplier' => [FinanceArchive::TYPE_SUPPLIER],
            'receipts' => [FinanceArchive::TYPE_RECEIPT],
            'profits' => FinanceArchive::PROFIT_TYPES,
            default => [
                FinanceArchive::TYPE_ACCOUNT,
                FinanceArchive::TYPE_SUPPLIER,
                FinanceArchive::TYPE_RECEIPT,
                ...FinanceArchive::PROFIT_TYPES,
            ],
        };

        $ids = [];

        foreach (FinanceArchive::query()->whereIn('subject_type', $types)->get() as $archive) {
            $summary = $this->resolveSummary($archive->subject_type, (int) $archive->subject_id);
            $haystack = strtolower(implode(' ', array_filter([
                $summary['title'],
                $summary['subtitle'],
                (string) ($summary['meta']['client'] ?? ''),
            ])));

            if (str_contains($haystack, strtolower($search))) {
                $ids[] = $archive->id;
            }
        }

        return $ids;
    }
}
