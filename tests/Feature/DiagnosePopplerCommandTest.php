<?php

use App\Services\Pdf\PopplerTextExtractor;

it('prints poppler resolution details', function () {
    $this->artisan('xl:diagnose-poppler')
        ->expectsOutputToContain('NATIVEPHP_EXTRAS_PATH:')
        ->expectsOutputToContain('base_path:');

    $available = app(PopplerTextExtractor::class)->isAvailable();

    if ($available) {
        $this->artisan('xl:diagnose-poppler')->assertSuccessful();
    } else {
        $this->artisan('xl:diagnose-poppler')->assertFailed();
    }
});
