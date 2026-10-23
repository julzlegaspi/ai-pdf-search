<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'chunk_index')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->unsignedInteger('chunk_index')->default(0);
            });
        }

        // Find and remove an existing unique constraint on
        // (relative_path, page_number), regardless of its name.
        $constraints = DB::select("
            SELECT c.conname
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE t.relname = 'documents'
              AND n.nspname = current_schema()
              AND c.contype = 'u'
              AND (
                  SELECT array_agg(a.attname ORDER BY k.ordinality)
                  FROM unnest(c.conkey) WITH ORDINALITY AS k(attnum, ordinality)
                  JOIN pg_attribute a
                    ON a.attrelid = t.oid
                   AND a.attnum = k.attnum
              ) = ARRAY['relative_path', 'page_number']::name[]
        ");

        foreach ($constraints as $constraint) {
            $name = str_replace('"', '""', $constraint->conname);

            DB::statement(
                'ALTER TABLE documents DROP CONSTRAINT "' . $name . '"'
            );
        }

        // Add the new chunk-level uniqueness rule.
        Schema::table('documents', function (Blueprint $table) {
            $table->unique(
                ['relative_path', 'page_number', 'chunk_index'],
                'documents_page_chunk_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_page_chunk_unique');

            $table->dropColumn('chunk_index');
        });
    }
};
