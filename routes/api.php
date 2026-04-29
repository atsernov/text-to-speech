<?php

use App\Http\Controllers\Api\AudioController;
use App\Http\Controllers\Api\TextExtractorController;
use App\Http\Controllers\Api\UrlExtractorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/extract-text', [TextExtractorController::class, 'extract'])->middleware('throttle:standard')->name('text.extract');
Route::post('/extract-pdf', [TextExtractorController::class, 'extractPdf'])->middleware('throttle:standard')->name('pdf.extract');

Route::post('/extract-url', [UrlExtractorController::class, 'extract'])->middleware('throttle:heavy')->name('url.extract');

Route::post('/synthesize', [AudioController::class, 'synthesize'])->middleware('throttle:heavy')->name('synthesize');

Route::get('/synthesis/status/{jobId}', [AudioController::class, 'status'])->middleware('throttle:light')->name('synthesis.status');
Route::get('/audio/{filename}', [AudioController::class, 'stream'])->middleware('throttle:light')->name('audio.stream');
Route::get('/my-files', [AudioController::class, 'myFiles'])->middleware('throttle:light')->name('my.files');
Route::delete('/my-files/{id}', [AudioController::class, 'deleteFile'])->middleware('throttle:light')->name('my.files.delete');
