<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateFileStorage
{
    public const DISK = 'local';

    public const LEGACY_DISK = 'public';

    public static function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, self::DISK);
    }

    public static function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    public static function diskForPath(string $path): ?Filesystem
    {
        if (Storage::disk(self::DISK)->exists($path)) {
            return Storage::disk(self::DISK);
        }

        if (Storage::disk(self::LEGACY_DISK)->exists($path)) {
            return Storage::disk(self::LEGACY_DISK);
        }

        return null;
    }

    public static function streamDownload(
        string $path,
        string $downloadName,
        ?string $mime = null,
    ): StreamedResponse {
        $disk = self::diskForPath($path);

        if ($disk === null) {
            abort(404, 'Archivo no encontrado.');
        }

        $mime ??= $disk->mimeType($path) ?: 'application/octet-stream';

        return $disk->response($path, $downloadName, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($downloadName) . '"',
        ]);
    }

    public static function reservationDocumentFileUrl(int $documentId): string
    {
        return 'reservation-documents/'.$documentId.'/file';
    }

    public static function paymentReceiptFileUrl(int $receiptId): string
    {
        return 'payment-receipts/'.$receiptId.'/file';
    }
}
