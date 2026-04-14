<?php

use App\Http\Controllers\Api\AudioController;
use App\Http\Controllers\Api\TextExtractorController;
use App\Http\Controllers\Api\UrlExtractorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/extract-text', [TextExtractorController::class, 'extract'])->name('text.extract');

Route::post('/extract-url', [UrlExtractorController::class, 'extract'])->name('url.extract');

Route::post('/synthesize', [AudioController::class, 'synthesize'])->name('synthesize');
Route::get('/synthesis/status/{jobId}', [AudioController::class, 'status'])->name('synthesis.status');
Route::get('/audio/{filename}', [AudioController::class, 'stream'])->name('audio.stream');
Route::get('/my-files', [AudioController::class, 'myFiles'])->name('my.files');
Route::delete('/my-files/{id}', [AudioController::class, 'deleteFile'])->name('my.files.delete');
