<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use OpenAI;

class GenerateDocumentEmbeddings extends Command
{
    protected $signature = 'documents:embed
                        {--limit=100 : Maximum pages to process}
                        {--file= : Relative path of one PDF}';

    protected $description =
        'Generate OpenAI embeddings for indexed PDF pages';

    public function handle(): int
    {
        $apiKey = config('services.openai.key')
            ?: env('OPENAI_API_KEY');

        if (! $apiKey) {
            $this->error('OPENAI_API_KEY is not configured.');

            return self::FAILURE;
        }

        $client = OpenAI::client($apiKey);

        $limit = max(1, (int) $this->option('limit'));

        
        $file = $this->option('file');

        $query = Document::query()
            ->whereNull('embedding')
            ->whereNotNull('content')
            ->whereRaw("length(trim(content)) > 0");

        if ($file !== null && $file !== '') {
            $query->where('relative_path', $file);
        }

        $documents = $query
            ->orderBy('id')
            ->limit($limit)
            ->get();


        if ($documents->isEmpty()) {
            $this->info('No pages need embeddings.');

            return self::SUCCESS;
        }

        $success = 0;
        $errors = 0;

        foreach ($documents as $document) {
            try {
                // Keep the initial version within a conservative
                // input size. We will implement chunking later.
                $text = mb_substr($document->content, 0, 8000);

                $response = $client->embeddings()->create([
                    'model' => config(
                        'services.openai.embedding_model',
                        'text-embedding-3-small'
                    ),
                    'input' => $text,
                ]);

                $vector = $response->embeddings[0]->embedding;

                $vectorString = '[' . implode(',', $vector) . ']';

                // Use a parameterized query for the vector value.
                \Illuminate\Support\Facades\DB::update(
                    'UPDATE documents
                     SET embedding = CAST(? AS vector), updated_at = NOW()
                     WHERE id = ?',
                    [$vectorString, $document->id]
                );

                $success++;

                $this->info(
                    "Embedded: {$document->relative_path} " .
                    "(page {$document->page_number})"
                );
            } catch (\Throwable $e) {
                $errors++;

                $this->error(
                    "Failed for document ID {$document->id}: " .
                    $e->getMessage()
                );
            }
        }

        $this->info(
            "Finished. Embedded: {$success}; errors: {$errors}."
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
