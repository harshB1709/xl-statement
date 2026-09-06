<?php

use App\Livewire\Converter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

Route::get('/', Converter::class)->name('home');

Route::get('/exports/{file}', function (string $file): BinaryFileResponse {
    abort_unless(preg_match('/^[A-Za-z0-9._-]+\.xlsx$/', $file) === 1, 404);

    $path = storage_path('app/exports/'.$file);
    abort_unless(is_file($path), 404);

    return response()->download($path, $file);
})->name('exports.download');
