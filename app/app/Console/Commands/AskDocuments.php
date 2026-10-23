<?php

namespace App\Console\Commands;

use App\Services\DocumentAnswerService;
use Illuminate\Console\Command;

class AskDocuments extends Command
{
    protected $signature = 'documents:ask {question}';

    protected $description =
        'Ask a question and answer it using indexed PDFs';

    public function handle(DocumentAnswerService $service): int
    {
        try {
            $result = $service->ask(
                (string) $this->argument('question')
            );

            $this->newLine();
            $this->info('ANSWER');
            $this->line($result['answer']);

            $this->newLine();
            $this->info('SOURCES');

            foreach ($result['sources'] as $source) {
                $this->line(
                    "[{$source['label']}] {$source['path']} " .
                    "(page {$source['page']})"
                );
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Question failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
