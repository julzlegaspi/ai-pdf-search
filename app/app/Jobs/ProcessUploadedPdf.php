<?php

namespace App\Jobs;

use App\Models\PdfUpload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $upload->update([
            'status' => 'processing',
            'started_at' => now(),
            'error_message' => null,
        ]);

        // try {
        //     $indexExitCode = Artisan::call('documents:index');

        //     if ($indexExitCode !== 0) {
        //         throw new \RuntimeException(
        //             'PDF indexing failed: ' . Artisan::output()
        //         );
        //     }

        //     // Generate embeddings for missing pages.
        //     do {
        //         $embedExitCode = Artisan::call('documents:embed', [
        //             '--limit' => 100,
        //         ]);

        //         if ($embedExitCode !== 0) {
        //             throw new \RuntimeException(
        //                 'Embedding generation failed: ' .
        //                 Artisan::output()
        //             );
        //         }

        //         $remaining = DB::table('documents')
        //             ->whereNull('embedding')
        //             ->whereNotNull('content')
        //             ->whereRaw("length(trim(content)) > 0")
        //             ->count();

        //     } while ($remaining > 0);

        //     $pages = DB::table('documents')
        //         ->where('relative_path', $upload->stored_filename)
        //         ->selectRaw(
        //             'COUNT(*) AS total_pages, ' .
        //             'COUNT(embedding) AS embedded_pages'
        //         )
        //         ->first();

        //     $upload->update([
        //         'total_pages' => $pages->total_pages,
        //         'embedded_pages' => $pages->embedded_pages,
        //         'status' => 'ready',
        //         'completed_at' => now(),
        //     ]);

        //     Log::info('PDF processing completed', [
        //         'file' => $upload->stored_filename,
        //     ]);
        // } catch (\Throwable $e) {
        //     $upload->update([
        //         'status' => 'failed',
        //         'error_message' => $e->getMessage(),
        //     ]);

        //     Log::error('PDF processing failed', [
        //         'file' => $upload->stored_filename,
        //         'error' => $e->getMessage(),
        //     ]);

        //     throw $e;
        // }
        
        try {
            // Index only the uploaded PDF.
            $indexExitCode = Artisan::call('documents:index', [
                '--file' => $upload->stored_filename,
            ]);

            if ($indexExitCode !== 0) {
                throw new \RuntimeException(
                    'PDF indexing failed: ' . Artisan::output()
                );
            }

            // Embed only pages belonging to this PDF.
            do {
                $embedExitCode = Artisan::call('documents:embed', [
                    '--file' => $upload->stored_filename,
                    '--limit' => 100,
                ]);

                if ($embedExitCode !== 0) {
                    throw new \RuntimeException(
                        'Embedding generation failed: ' . Artisan::output()
                    );
                }

                $pages = DB::table('documents')
                    ->where('relative_path', $upload->stored_filename)
                    ->selectRaw(
                        'COUNT(*) AS total_pages, ' .
                        'COUNT(embedding) AS embedded_pages'
                    )
                    ->first();

                $upload->update([
                    'total_pages' => $pages->total_pages,
                    'embedded_pages' => $pages->embedded_pages,
                ]);

                $remaining = DB::table('documents')
                    ->where('relative_path', $upload->stored_filename)
                    ->whereNull('embedding')
                    ->whereNotNull('content')
                    ->whereRaw("length(trim(content)) > 0")
                    ->count();

            } while ($remaining > 0);

            if ($pages->total_pages === 0) {
                throw new \RuntimeException(
                    'No PDF pages were indexed.'
                );
            }

            if ($pages->embedded_pages < $pages->total_pages) {
                throw new \RuntimeException(
                    'Some PDF pages have no embeddings.'
                );
            }

            $upload->update([
                'status' => 'ready',
                'completed_at' => now(),
            ]);

            Log::info('PDF processing completed', [
                'file' => $upload->stored_filename,
            ]);
        } catch (\Throwable $e) {
            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('PDF processing failed', [
                'file' => $upload->stored_filename,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

    }
}