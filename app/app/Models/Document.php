<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'relative_path',
        'filename',
        'page_number',
        'content',
        'file_hash',
    ];
}