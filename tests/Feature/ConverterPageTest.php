<?php

use App\Livewire\Converter;
use Livewire\Livewire;

it('renders the converter home page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSeeLivewire(Converter::class)
        ->assertSee('favicon.svg', false)
        ->assertSee('XL Statement', false);
});

it('starts on the files step', function () {
    Livewire::test(Converter::class)
        ->assertSet('step', 1)
        ->assertSee('Choose PDFs')
        ->assertDontSee('storage/app/exports')
        ->assertDontSee('3. Export');
});

it('shows excel column toggles on the map step', function () {
    Livewire::test(Converter::class)
        ->set('step', 2)
        ->set('activeFingerprint', 'fp')
        ->set('layouts', [
            'fp' => [
                'fingerprint' => 'fp',
                'name' => 'Test',
                'header_cells' => ['Date', 'Description', 'Debit', 'Credit', 'Balance'],
                'sample_rows' => [],
                'targets' => [],
                'date_format' => 'd-m-Y',
                'amount_style' => 'separate_dr_cr',
                'file_paths' => [],
                'reconciliation' => 100,
                'warnings' => [],
                'transaction_count' => 0,
            ],
        ])
        ->assertSee('Columns in Excel')
        ->assertSee('Value Date')
        ->assertSee('Source file');
});

it('detects native desktop from NATIVEPHP_RUNNING', function () {
    $_ENV['NATIVEPHP_RUNNING'] = 'true';
    $_SERVER['NATIVEPHP_RUNNING'] = 'true';

    try {
        Livewire::test(Converter::class)
            ->assertSet('isNative', true);
    } finally {
        unset($_ENV['NATIVEPHP_RUNNING'], $_SERVER['NATIVEPHP_RUNNING']);
    }
});
