<?php

use App\Actions\ConvertStatements;
use App\Data\ConversionResult;
use App\Enums\AmountStyle;
use App\Enums\TargetField;
use App\Livewire\Converter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Papier\Elements\Text;
use Papier\Encryption\EncryptionAlgorithm;
use Papier\Encryption\StandardSecurityHandler;
use Papier\PdfDocument;

uses(RefreshDatabase::class);

it('shows an incorrect password message after a failed unlock', function () {
    $path = storage_path('framework/testing/converter-unlock-locked.pdf');
    @unlink($path);

    $doc = PdfDocument::create();
    $font = $doc->addFont('Helvetica');
    $page = $doc->addPage();
    $page->add(
        Text::write('14-05-2025 Initial Funding 25000.00 25000.00')
            ->at(72, 750)
            ->font($font, 12),
    );
    $doc->encrypt('correct-secret', 'owner-secret', StandardSecurityHandler::PERM_ALL, EncryptionAlgorithm::Aes_256);
    $doc->save($path);

    Livewire::test(Converter::class)
        ->set('files', [[
            'path' => $path,
            'name' => 'locked.pdf',
            'status' => 'password',
            'message' => 'Password required',
            'fingerprint' => null,
            'row_count' => 0,
            'password' => 'wrong-secret',
        ]])
        ->call('unlock', 0)
        ->assertSet('files.0.status', 'password')
        ->assertSet('files.0.message', 'Incorrect password. Try again.');
});

it('passes stored unlock passwords when converting to excel', function () {
    $path = storage_path('framework/testing/converter-convert-locked.txt');
    file_put_contents($path, 'placeholder');

    $passwordsSeen = null;

    $this->mock(ConvertStatements::class, function ($mock) use (&$passwordsSeen, $path) {
        $mock->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$args) use (&$passwordsSeen, $path) {
                $passwordsSeen = $args[4] ?? [];

                expect($passwordsSeen[$path] ?? null)->toBe('unlock-secret');

                return new ConversionResult(
                    outputPath: storage_path('framework/testing/converter-convert-out.xlsx'),
                    fileCount: 1,
                    transactionCount: 3,
                );
            });
    });

    $fingerprint = 'fp-locked-test';

    Livewire::test(Converter::class)
        ->set('step', 2)
        ->set('outputName', 'out.xlsx')
        ->set('outputDirectory', storage_path('framework/testing'))
        ->set('saveProfiles', false)
        ->set('files', [[
            'path' => $path,
            'name' => 'locked.pdf',
            'status' => 'mapped',
            'message' => null,
            'fingerprint' => $fingerprint,
            'row_count' => 3,
            'password' => 'unlock-secret',
        ]])
        ->set('layouts', [
            $fingerprint => [
                'fingerprint' => $fingerprint,
                'name' => 'Test layout',
                'header_cells' => ['Date', 'Description', 'Debit', 'Credit', 'Balance'],
                'sample_rows' => [],
                'targets' => [
                    '0' => TargetField::Date->value,
                    '1' => TargetField::Description->value,
                    '2' => TargetField::Debit->value,
                    '3' => TargetField::Credit->value,
                    '4' => TargetField::Balance->value,
                ],
                'date_format' => 'd-m-Y',
                'amount_style' => AmountStyle::SeparateDrCr->value,
                'file_paths' => [$path],
                'reconciliation' => 100,
                'warnings' => [],
                'transaction_count' => 3,
            ],
        ])
        ->call('convert')
        ->assertSet('resultMessage', 'Ready converter-convert-out.xlsx · 3 rows');

    expect($passwordsSeen[$path] ?? null)->toBe('unlock-secret');
});
