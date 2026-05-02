<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Index;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', [Index::class, 'index'])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth', EnsureUserIsAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/jobs', [AdminController::class, 'jobs'])->name('jobs');
    Route::get('/files', [AdminController::class, 'files'])->name('files');

    Route::delete('/jobs/{id}', [AdminController::class, 'cancelJob'])->name('jobs.cancel');
    Route::delete('/files/{id}', [AdminController::class, 'deleteFile'])->name('files.delete');
    Route::post('/files/{id}/cancel', [AdminController::class, 'cancelAdminFile'])->name('files.cancel');
});

require __DIR__.'/settings.php';
