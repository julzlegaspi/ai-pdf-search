
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE documents ADD COLUMN embedding vector(1536) NULL'
        );

        DB::statement(
            'CREATE INDEX documents_embedding_hnsw_idx
             ON documents USING hnsw (embedding vector_cosine_ops)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS documents_embedding_hnsw_idx'
        );

        DB::statement(
            'ALTER TABLE documents DROP COLUMN IF EXISTS embedding'
        );
    }
};
