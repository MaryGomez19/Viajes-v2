<?php

namespace App\Console\Commands;

use App\Models\PaymentReceipt;
use App\Models\ReservationDocument;
use App\Support\PrivateFileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigratePrivateFilesCommand extends Command
{
    protected $signature = 'files:migrate-to-private {--dry-run : Solo mostrar qué se movería}';

    protected $description = 'Mueve comprobantes y documentos de reserva del disco public al disco local (privado).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;

        $paths = ReservationDocument::query()->pluck('file_path', 'id')
            ->merge(PaymentReceipt::query()->pluck('file_path', 'id'));

        foreach ($paths->unique()->filter() as $path) {
            if (Storage::disk(PrivateFileStorage::DISK)->exists($path)) {
                continue;
            }

            if (! Storage::disk(PrivateFileStorage::LEGACY_DISK)->exists($path)) {
                continue;
            }

            $this->line($path);

            if ($dryRun) {
                $moved++;

                continue;
            }

            $contents = Storage::disk(PrivateFileStorage::LEGACY_DISK)->get($path);
            Storage::disk(PrivateFileStorage::DISK)->put($path, $contents);
            Storage::disk(PrivateFileStorage::LEGACY_DISK)->delete($path);
            $moved++;
        }

        $this->info($dryRun
            ? "Archivos que se migrarían: {$moved}"
            : "Archivos migrados al almacenamiento privado: {$moved}");

        return self::SUCCESS;
    }
}
