<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\ReservationDocument;
use App\Support\PrivateFileStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReservationDocumentController extends Controller
{
    public function index(Reservation $reservation)
    {
        $documents = $reservation->documents()->get()->map(function ($doc) {
            return [
                'id' => $doc->id,
                'type' => $doc->type,
                'original_name' => $doc->original_name,
                'url' => PrivateFileStorage::reservationDocumentFileUrl($doc->id),
                'created_at' => $doc->created_at,
            ];
        });

        return response()->json($documents);
    }

    public function store(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['hotel_voucher', 'payment', 'transfer', 'other'])],
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'], // 10 MB
        ]);

        $existing = $reservation->documents()->where('type', $data['type'])->first();
        if ($existing) {
            PrivateFileStorage::delete($existing->file_path);
            $existing->delete();
        }

        $path = PrivateFileStorage::store(
            $request->file('file'),
            "reservations/{$reservation->id}/documents",
        );

        $document = $reservation->documents()->create([
            'type' => $data['type'],
            'original_name' => $request->file('file')->getClientOriginalName(),
            'file_path' => $path,
        ]);

        return response()->json([
            'message' => 'Documento subido correctamente',
            'document' => [
                'id' => $document->id,
                'type' => $document->type,
                'original_name' => $document->original_name,
                'url' => PrivateFileStorage::reservationDocumentFileUrl($document->id),
            ],
        ], 201);
    }

    public function destroy(ReservationDocument $document)
    {
        PrivateFileStorage::delete($document->file_path);
        $document->delete();

        return response()->json(['message' => 'Documento eliminado correctamente']);
    }
}
