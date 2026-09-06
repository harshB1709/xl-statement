<?php

namespace App\Providers;

use App\Services\Pdf\PdfTextExtractor;
use App\Services\Pdf\PreferPhpPdfTextExtractor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PdfTextExtractor::class, PreferPhpPdfTextExtractor::class);
    }

    public function boot(): void
    {
        //
    }
}
