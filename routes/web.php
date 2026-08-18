<?php

declare(strict_types=1);

use App\Http\Controllers\PlaygroundController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/playground'));

// Dijaga config('ocr.playground_enabled') di dalam controller — bukan di sini,
// supaya rute tetap terdaftar dan 404-nya konsisten dengan sisa aplikasi.
Route::get('/playground', [PlaygroundController::class, 'show']);
Route::post('/playground', [PlaygroundController::class, 'analyze']);
