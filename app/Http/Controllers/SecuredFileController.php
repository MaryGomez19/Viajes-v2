<?php

namespace App\Http\Controllers;

use App\Models\PaymentReceipt;
use App\Models\ReservationDocument;
use App\Support\PrivateFileStorage;
use Illuminate\Http\Request;

class SecuredFileController extends Controller
{
    public function reservationDocument(Request $request, ReservationDocument $document)
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('admin')) {
            abort(403, 'No autorizado.');
        }

        return PrivateFileStorage::streamDownload(
            $document->file_path,
            $document->original_name ?: 'documento.pdf',
        );
    }

    public function paymentReceipt(Request $request, PaymentReceipt $paymentReceipt)
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'No autenticado.');
        }

        $paymentReceipt->loadMissing('reservation');

        if ($user->hasRole('admin')) {
            return PrivateFileStorage::streamDownload(
                $paymentReceipt->file_path,
                $paymentReceipt->original_name ?: 'comprobante',
            );
        }

        if (
            $user->hasRole('cliente')
            && $paymentReceipt->reservation
            && (int) $paymentReceipt->reservation->user_id === (int) $user->id
        ) {
            return PrivateFileStorage::streamDownload(
                $paymentReceipt->file_path,
                $paymentReceipt->original_name ?: 'comprobante',
            );
        }

        abort(403, 'No autorizado.');
    }
}
