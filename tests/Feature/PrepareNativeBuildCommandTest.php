<?php

it('passes prepare-native-build when branding and poppler extras look sane', function () {
    $pdftotext = base_path('extras/win/pdftotext.exe');

    if (! is_file($pdftotext) || (filesize($pdftotext) ?: 0) < 1024) {
        test()->markTestSkipped('Windows Poppler extras missing or LFS stub');
    }

    config([
        'app.name' => 'XL Statement',
        'nativephp.description' => 'Convert PDF bank statements into Excel',
    ]);

    $this->artisan('xl:prepare-native-build')
        ->expectsOutputToContain('APP_NAME / config(app.name): XL Statement')
        ->expectsOutputToContain('Ready.')
        ->assertSuccessful();
});

it('fails prepare-native-build when APP_NAME is still Laravel', function () {
    config([
        'app.name' => 'Laravel',
        'nativephp.description' => 'Convert PDF bank statements into Excel',
    ]);

    $this->artisan('xl:prepare-native-build --skip-lfs-check')
        ->expectsOutputToContain('Set APP_NAME="XL Statement"')
        ->assertFailed();
});
