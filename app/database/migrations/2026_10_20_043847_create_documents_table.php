<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('relative_path', 1024);
            $table->string('filename');
            $table->unsignedInteger('page_number');
            $table->longText('content');
            $table->string('file_hash', 64);
            $table->timestamps();

            $table->unique(
                ['relative_path', 'page_number'],
                'documents_path_page_unique'
            );

            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};