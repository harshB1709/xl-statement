<?php

use App\Actions\ConvertStatements;
use App\Data\ColumnMapping;
use App\Data\ExportOptions;
use App\Enums\AmountStyle;
use App\Enums\TargetField;
use App\Services\Pdf\ExtractedText;
use App\Services\Pdf\PdfTextExtractor;

it('converts statements using a fake extractor through the command pipeline', function () {
    $text = file_get_contents(base_path('tests/Fixtures/layouts/separate-dr-cr/input.txt'));

    $this->app->instance(PdfTextExtractor::class, new class($text) implements PdfTextExtractor
    {
        public function __construct(private string $text) {}

        public function extract(string $path, ?string $password = null): ExtractedText
        {
            return new ExtractedText([$this->text], $path);
        }
    });

    $output = storage_path('framework/testing/cli-out.xlsx');
    @unlink($output);

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

    $result = app(ConvertStatements::class)->handle(
        paths: [base_path('tests/Fixtures/layouts/separate-dr-cr/input.txt')],
        options: new ExportOptions(outputPath: $output),
        defaultMapping: $mapping,
    );

    expect($result->transactionCount)->toBeGreaterThan(0)
        ->and(file_exists($output))->toBeTrue();
});
