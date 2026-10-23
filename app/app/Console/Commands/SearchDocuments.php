<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenAI;

class SearchDocuments extends Command
{
    protected $signature = 'documents:search {question}';

    protected $description =
        'Search indexed PDF pages using semantic similarity';

    public function handle(): int
    {
        $apiKey = config('services.openai.key');

        if (! $apiKey) {
            $this->error(
                'OPENAI_API_KEY is not configured.'
            );

            return self::FAILURE;
        }

        $question = trim((string) $this->argument('question'));

        if ($question === '') {
            $this->error('Please enter a question.');

            return self::FAILURE;
        }

        $client = OpenAI::client($apiKey);

        try {
            // Convert the question into an embedding.
            $response = $client->embeddings()->create([
                'model' => config(
                    'services.openai.embedding_model',
                    'text-embedding-3-small'
                ),
                'input' => $question,
            ]);

            $embedding = $response->embeddings[0]->embedding;
            $vector = '[' . implode(',', $embedding) . ']';

            // Retrieve the five closest PDF pages.
            $results = DB::select(
                'SELECT
                    id,
                    relative_path,
                    filename,
                    page_number,
                    content,
                    embedding <=> CAST(? AS vector) AS distance
                 FROM documents
                 WHERE embedding IS NOT NULL
                 ORDER BY embedding <=> CAST(? AS vector)
                 LIMIT 5',
                [$vector, $vector]
            );

            if (count($results) === 0) {
                $this->warn('No embedded PDF pages were found.');

                return self::SUCCESS;
            }

            $this->info('Top matching PDF pages:');

            foreach ($results as $index => $result) {
                $this->newLine();
                $this->line(
                    ($index + 1) . '. ' .
                    $result->relative_path .
                    ' — Page ' . $result->page_number
                );

                $this->line(
                    'Similarity distance: ' .
                    number_format((float) $result->distance, 4)
                );

                $this->line(
                    mb_substr($result->content, 0, 1200)
                );
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Search failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
