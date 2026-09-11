<?php

use App\Livewire\Converter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

Route::get('/', Converter::class)->name('home');

Route::get('/exports/{file}', function (string $file): BinaryFileResponse {
    abort_unless(preg_match('/^[A-Za-z0-9._-]+\.xlsx$/', $file) === 1, 404);

    foreach ([
        storage_path('framework/tmp/'.$file),
        storage_path('app/exports/'.$file),
    ] as $path) {
        if (is_file($path)) {
            return response()->download($path, $file)->deleteFileAfterSend(true);
        }
    }

    abort(404);
})->name('exports.download');
