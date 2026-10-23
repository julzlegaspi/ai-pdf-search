<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DocumentSearchController::class, 'index'])
    ->name('documents.search');

Route::post('/ask', [DocumentSearchController::class, 'ask'])
    ->name('documents.ask');

Route::get('/documents', [DocumentController::class, 'index'])
    ->name('documents.index');

Route::post('/documents/upload', [DocumentController::class, 'upload'])
    ->name('documents.upload');

Route::get('/documents/statuses', [DocumentController::class, 'statuses'])
    ->name('documents.statuses');

Route::delete('/documents/uploads/{upload}', [DocumentController::class, 'destroy'])
    ->name('documents.destroy');

Route::post('/documents/uploads/{upload}/reprocess', [DocumentController::class, 'reprocess'])
    ->name('documents.reprocess');
