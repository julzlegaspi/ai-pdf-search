<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\TextChunker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;
use Throwable;

class IndexDocuments extends Command
{
    protected $signature = 'documents:index
        {--file= : UUID filename of one uploaded PDF}
        {--force : Reindex even if the PDF has not changed}';

    protected $description =
        'Index PDFs as text chunks, using OCR for scanned pages';

    public function handle(): int
    {
        $root = realpath('/documents');

        if ($root === false || ! is_dir($root)) {
            $this->error('Documents directory not found.');
            return self::FAILURE;
        }

        $fileOption = $this->option('file');
        $force = (bool) $this->option('force');

        if ($fileOption !== null && $fileOption !== '') {
            if (! preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.pdf$/i',
                $fileOption
            )) {
                $this->error('Invalid uploaded PDF filename.');
                return self::FAILURE;
            }

            $target = realpath(
                $root . DIRECTORY_SEPARATOR . $fileOption
            );

            if (
                $target === false ||
                ! str_starts_with(
                    $target,
                    $root . DIRECTORY_SEPARATOR
                ) ||
                ! is_file($target)
            ) {
                $this->error('PDF file not found.');
                return self::FAILURE;
            }

            $files = [new \SplFileInfo($target)];

            $this->info("Indexing only: {$fileOption}");
        } else {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS
                )
            );
        }

        $parser = new Parser();
        $chunker = new TextChunker();

        $indexedFiles = 0;
        $indexedChunks = 0;
        $errors = 0;

        foreach ($files as $file) {
            if (
                ! $file->isFile() ||
                strtolower($file->getExtension()) !== 'pdf'
            ) {
                continue;
            }

            $absolutePath = $file->getPathname();

            $resolvedPath = realpath($absolutePath);

            if (
                $resolvedPath === false ||
                ! str_starts_with(
                    $resolvedPath,
                    $root . DIRECTORY_SEPARATOR
                )
            ) {
                $this->warn('Skipping unsafe PDF path.');
                $errors++;
                continue;
            }

            $relativePath = str_replace(
                '\\',
                '/',
                substr(
                    $absolutePath,
                    strlen($root) + 1
                )
            );

            $hash = hash_file('sha256', $absolutePath);

            if ($hash === false) {
                $this->warn("Cannot hash: {$relativePath}");
                $errors++;
                continue;
            }

            $existing = Document::where(
                'relative_path',
                $relativePath
            );

            $unchanged = (clone $existing)
                ->where('file_hash', $hash)
                ->exists();

            if ($unchanged && ! $force) {
                $this->line("Unchanged: {$relativePath}");
                continue;
            }

            try {
                $pdf = $parser->parseFile($absolutePath);
                $pages = $pdf->getPages();

                $records = [];

                foreach ($pages as $index => $page) {
                    $pageNumber = $index + 1;
                    $content = trim($page->getText());

                    if (mb_strlen($content) < 20) {
                        $ocrText = $this->ocrPage(
                            $absolutePath,
                            $pageNumber
                        );

                        if ($ocrText !== '') {
                            $content = $ocrText;
                        }
                    }

                    $chunks = $chunker->split($content);

                    if (count($chunks) === 0) {
                        $this->warn(
                            "No readable text on page {$pageNumber}"
                        );

                        continue;
                    }

                    foreach ($chunks as $chunkIndex => $chunk) {
                        $records[] = [
                            'relative_path' => $relativePath,
                            'filename' => $file->getFilename(),
                            'page_number' => $pageNumber,
                            'chunk_index' => $chunkIndex,
                            'content' => $chunk,
                            'file_hash' => $hash,
                        ];
                    }

                    $this->line(
                        "  Page {$pageNumber}: " .
                        count($chunks) . ' chunks'
                    );
                }

                if (count($records) === 0) {
                    throw new RuntimeException(
                        'No readable text found in this PDF.'
                    );
                }

                // Replace old pages only after extraction succeeds.
                DB::transaction(function () use (
                    $relativePath,
                    $records
                ) {
                    Document::where(
                        'relative_path',
                        $relativePath
                    )->delete();

                    foreach ($records as $record) {
                        Document::create($record);
                    }
                });

                $indexedFiles++;
                $indexedChunks += count($records);

                $this->info(
                    "Indexed: {$relativePath} (" .
                    count($pages) . ' pages, ' .
                    count($records) . ' chunks)'
                );
            } catch (Throwable $e) {
                $errors++;

                $this->error(
                    "Failed: {$relativePath} — " .
                    $e->getMessage()
                );
            }
        }

        $this->info(
            "Finished. Files: {$indexedFiles}; " .
            "chunks: {$indexedChunks}; errors: {$errors}."
        );

        return $errors > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function ocrPage(
        string $pdfPath,
        int $pageNumber
    ): string {
        $prefix = tempnam(
            sys_get_temp_dir(),
            'pdfocr_'
        );

        if ($prefix === false) {
            throw new RuntimeException(
                'Could not create temporary OCR path.'
            );
        }

        unlink($prefix);

        $imagePath = $prefix . '.png';

        try {
            $render = new Process([
                'pdftoppm',
                '-f', (string) $pageNumber,
                '-l', (string) $pageNumber,
                '-r', '200',
                '-png',
                '-singlefile',
                $pdfPath,
                $prefix,
            ]);

            $render->setTimeout(120);
            $render->mustRun();

            if (! is_file($imagePath)) {
                throw new RuntimeException(
                    "Rendered image missing for page {$pageNumber}."
                );
            }

            $ocr = new Process([
                'tesseract',
                $imagePath,
                'stdout',
                '-l', 'eng',
                '--psm', '3',
            ]);

            $ocr->setTimeout(120);
            $ocr->mustRun();

            $text = trim($ocr->getOutput());

            if ($text !== '') {
                $this->line(
                    "  OCR completed for page {$pageNumber}"
                );
            }

            return $text;
        } finally {
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }
    }
}
