<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;
use Throwable;
use RuntimeException;

class IndexDocuments extends Command
{
    protected $signature = 'documents:index
                        {--file= : Relative path of one PDF to index}';

    protected $description =
        'Index PDFs and OCR pages that contain little or no text';

    public function handle(): int
    {
        
        $fileOption = $this->option('file');

        if ($fileOption !== null && $fileOption !== '') {
            $root = realpath('/documents');

            if ($root === false) {
                $this->error('Documents directory not found.');
                return self::FAILURE;
            }

            // Uploaded files use UUID filenames, not arbitrary paths.
            if (! preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.pdf$/i',
                $fileOption
            )) {
                $this->error('Invalid uploaded PDF filename.');
                return self::FAILURE;
            }

            $target = realpath($root . DIRECTORY_SEPARATOR . $fileOption);

            if ($target === false ||
                ! str_starts_with($target, $root . DIRECTORY_SEPARATOR) ||
                ! is_file($target)) {
                $this->error('PDF file not found.');
                return self::FAILURE;
            }

            $this->info("Indexing only: {$fileOption}");

            // Use $target as the only PDF to process.
        }


        $root = '/documents';

        if (! is_dir($root)) {
            $this->error("Directory not found: {$root}");

            return self::FAILURE;
        }

        $parser = new Parser();
        $count = 0;
        $errors = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($files as $file) {
            if (! $file->isFile()
                || strtolower($file->getExtension()) !== 'pdf') {
                continue;
            }

            $absolutePath = $file->getPathname();

            $relativePath = str_replace(
                '\\',
                '/',
                substr($absolutePath, strlen($root) + 1)
            );

            $hash = hash_file('sha256', $absolutePath);

            if ($hash === false) {
                $this->warn("Cannot hash: {$relativePath}");
                $errors++;
                continue;
            }

            $unchanged = Document::where(
                'relative_path',
                $relativePath
            )->where('file_hash', $hash)->exists();

            if ($unchanged) {
                $this->line("Unchanged: {$relativePath}");
                continue;
            }

            try {
                $pdf = $parser->parseFile($absolutePath);
                $pages = $pdf->getPages();

                DB::transaction(function () use (
                    $relativePath,
                    $file,
                    $hash,
                    $pages,
                    $absolutePath
                ) {
                    Document::where(
                        'relative_path',
                        $relativePath
                    )->delete();

                    foreach ($pages as $index => $page) {
                        $pageNumber = $index + 1;
                        $content = trim($page->getText());

                        // OCR pages with little extracted text.
                        if (mb_strlen($content) < 20) {
                            $content = $this->ocrPage(
                                $absolutePath,
                                $pageNumber
                            ) ?: $content;
                        }

                        Document::create([
                            'relative_path' => $relativePath,
                            'filename' => $file->getFilename(),
                            'page_number' => $pageNumber,
                            'content' => $content,
                            'file_hash' => $hash,
                        ]);
                    }
                });

                $count++;

                $this->info(
                    "Indexed: {$relativePath} (" .
                    count($pages) . ' pages)'
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
            "Finished. Files indexed: {$count}; errors: {$errors}."
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function ocrPage(
        string $pdfPath,
        int $pageNumber
    ): string {
        $prefix = tempnam(sys_get_temp_dir(), 'pdfocr_');

        if ($prefix === false) {
            throw new RuntimeException(
                'Could not create temporary OCR path.'
            );
        }

        // Poppler creates the image at PREFIX.png.
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
