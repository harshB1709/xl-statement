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
        ->assertSee('Source file')
        ->assertSee('Download Excel')
        ->assertSee('Start over');
});

it('paginates preview rows with next and previous controls', function () {
    $rows = [];

    for ($i = 1; $i <= 30; $i++) {
        $rows[] = [sprintf('%02d-01-2025', $i), "Row {$i}", '', (string) $i, (string) $i];
    }

    Livewire::test(Converter::class)
        ->set('step', 2)
        ->set('previewPerPage', 12)
        ->set('activeFingerprint', 'fp')
        ->set('layouts', [
            'fp' => [
                'fingerprint' => 'fp',
                'name' => 'Test',
                'header_cells' => ['Date', 'Description', 'Debit', 'Credit', 'Balance'],
                'sample_rows' => array_slice($rows, 0, 5),
                'raw_table' => [
                    'rows' => $rows,
                ],
                'targets' => [],
                'date_format' => 'd-m-Y',
                'amount_style' => 'separate_dr_cr',
                'file_paths' => ['a.pdf'],
                'reconciliation' => 100,
                'warnings' => [],
                'transaction_count' => 30,
            ],
        ])
        ->assertSet('previewPage', 1)
        ->assertSee('1–12 of 30')
        ->assertSee('Row 1')
        ->assertDontSee('Row 13')
        ->call('nextPreviewPage')
        ->assertSet('previewPage', 2)
        ->assertSee('13–24 of 30')
        ->assertSee('Row 13')
        ->call('previousPreviewPage')
        ->assertSet('previewPage', 1)
        ->assertSee('Row 1');
});

it('clears state and returns to files when starting over', function () {
    Livewire::test(Converter::class)
        ->set('step', 2)
        ->set('activeFingerprint', 'fp')
        ->set('previewPage', 3)
        ->set('layouts', [
            'fp' => [
                'fingerprint' => 'fp',
                'name' => 'Test',
                'header_cells' => ['Date'],
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
        ->set('files', [[
            'path' => '/tmp/a.pdf',
            'name' => 'a.pdf',
            'status' => 'table_found',
            'message' => null,
            'fingerprint' => 'fp',
            'row_count' => 1,
            'password' => '',
        ]])
        ->call('startOver')
        ->assertSet('step', 1)
        ->assertSet('layouts', [])
        ->assertSet('files', [])
        ->assertSet('activeFingerprint', '')
        ->assertSet('previewPage', 1)
        ->assertSee('Choose PDFs');
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
