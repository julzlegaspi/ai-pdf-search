<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfUpload extends Model
{
    protected $fillable = [
        'original_filename',
        'stored_filename',
        'status',
        'total_pages',
        'embedded_pages',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
