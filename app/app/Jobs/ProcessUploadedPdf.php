<?php

namespace App\Jobs;

use App\Models\PdfUpload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProcessUploadedPdf implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 2;

    public function __construct(
        public int $uploadId
    ) {}

    public function handle(): void
    {
        $upload = PdfUpload::findOrFail($this->uploadId);

        $filename = $upload->stored_filename;

        $upload->update([
            'status' => 'processing',
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
            'total_pages' => 0,
            'embedded_pages' => 0,
        ]);

        try {
            // Force reindexing to regenerate chunks.
            $exitCode = Artisan::call('documents:index', [
                '--file' => $filename,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    'PDF indexing failed: ' . Artisan::output()
                );
            }

            $totalPages = DB::table('documents')
                ->where('relative_path', $filename)
                ->distinct()
                ->count('page_number');

            $totalChunks = DB::table('documents')
                ->where('relative_path', $filename)
                ->count();

            if ($totalChunks === 0) {
                throw new RuntimeException(
                    'No readable PDF chunks were indexed.'
                );
            }

            $upload->update([
                'total_pages' => $totalPages,
            ]);

            $previousRemaining = $totalChunks + 1;

            do {
                
                $exitCode = Artisan::call('documents:embed', [
                    '--file' => $filename,
                    '--limit' => 100,
                ]);

                if ($exitCode !== 0) {
                    throw new RuntimeException(
                        'Embedding generation failed: ' .
                        Artisan::output()
                    );
                }

                $embeddedChunks = DB::table('documents')
                    ->where('relative_path', $filename)
                    ->whereNotNull('embedding')
                    ->count();

                $remaining = DB::table('documents')
                    ->where('relative_path', $filename)
                    ->whereNull('embedding')
                    ->count();

                // Count distinct pages that have at least
                // one embedded chunk.
                $embeddedPages = DB::table('documents')
                    ->where('relative_path', $filename)
                    ->whereNotNull('embedding')
                    ->distinct()
                    ->count('page_number');

                $upload->update([
                    'embedded_pages' => $embeddedPages,
                ]);

                Log::info('PDF embedding progress', [
                    'file' => $filename,
                    'total_chunks' => $totalChunks,
                    'embedded_chunks' => $embeddedChunks,
                    'remaining_chunks' => $remaining,
                ]);

                if ($remaining > 0 && $remaining >= $previousRemaining) {
                    $previousRemaining = $remaining;
                    throw new RuntimeException(
                        'Embedding generation made no progress.'
                    );
                }
            } while ($remaining > 0);

            $upload->update([
                'status' => 'ready',
                'embedded_pages' => $totalPages,
                'completed_at' => now(),
            ]);

            Log::info('PDF processing completed', [
                'file' => $filename,
                'pages' => $totalPages,
                'chunks' => $totalChunks,
            ]);
        } catch (Throwable $e) {
            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('PDF processing failed', [
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
