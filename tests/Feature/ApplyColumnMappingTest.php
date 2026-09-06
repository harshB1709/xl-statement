<?php

use App\Actions\ApplyColumnMapping;
use App\Actions\ExtractStatementTable;
use App\Data\ColumnMapping;
use App\Data\ExportOptions;
use App\Enums\AmountStyle;
use App\Enums\TargetField;
use App\Services\Excel\StatementWorkbookBuilder;
use App\Services\Pdf\ExtractedText;

it('builds a workbook from a mapped statement', function () {
    $text = file_get_contents(base_path('tests/Fixtures/layouts/separate-dr-cr/input.txt'));
    $table = app(ExtractStatementTable::class)->fromExtractedText(new ExtractedText([$text], 'acme.pdf'));

    $mapping = new ColumnMapping(
        targets: [
            0 => TargetField::Date,
            1 => TargetField::Description,
            2 => TargetField::Reference,
            3 => TargetField::Debit,
            4 => TargetField::Credit,
            5 => TargetField::Balance,
        ],
        dateFormat: 'd/m/Y',
        amountStyle: AmountStyle::SeparateDrCr,
    );

    $statement = app(ApplyColumnMapping::class)->handle($table, $mapping);
    $path = storage_path('framework/testing/mapped.xlsx');
    @unlink($path);

    app(StatementWorkbookBuilder::class)
        ->build([$statement], new ExportOptions(outputPath: $path))
        ->save($path);

    expect(file_exists($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(100);
});
