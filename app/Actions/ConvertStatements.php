<?php

namespace App\Actions;

use App\Data\ColumnMapping;
use App\Data\ConversionResult;
use App\Data\ExportOptions;
use App\Data\ParsedStatement;
use App\Services\Excel\StatementWorkbookBuilder;
use App\Services\Table\MappingSuggester;
use InvalidArgumentException;

class ConvertStatements
{
    public function __construct(
        private readonly ExtractStatementTable $extractStatementTable,
        private readonly ApplyColumnMapping $applyColumnMapping,
        private readonly MappingSuggester $mappingSuggester,
        private readonly StatementWorkbookBuilder $workbookBuilder,
    ) {}

    /**
     * @param  list<string>  $paths
     * @param  array<string, ColumnMapping>|null  $mappingsByFingerprint
     * @param  array<string, string|null>  $passwords
     * @param  array<string, string>  $pathLabels  absolute path => original display filename
     */
    public function handle(
        array $paths,
        ExportOptions $options,
        ?array $mappingsByFingerprint = null,
        ?ColumnMapping $defaultMapping = null,
        array $passwords = [],
        array $pathLabels = [],
    ): ConversionResult {
        if ($paths === []) {
            throw new InvalidArgumentException('At least one PDF path is required.');
        }

        $statements = [];
        $warnings = [];

        foreach ($paths as $path) {
            $password = $passwords[$path] ?? $passwords['*'] ?? null;
            $table = $this->extractStatementTable->handle($path, $password);

            if (isset($pathLabels[$path]) && $pathLabels[$path] !== '') {
                $table = $table->withDisplayName($pathLabels[$path]);
            }

            $mapping = $mappingsByFingerprint[$table->layoutFingerprint]
                ?? $defaultMapping
                ?? $this->mappingSuggester->suggest($table);

            $statement = $this->applyColumnMapping->handle($table, $mapping);
            $statements[] = $statement;
            $warnings = array_merge($warnings, $statement->warnings);
        }

        $writer = $this->workbookBuilder->build($statements, $options);
        $writer->save($options->outputPath);

        $transactionCount = array_sum(array_map(
            static fn (ParsedStatement $statement): int => count($statement->transactions),
            $statements,
        ));

        return new ConversionResult(
            outputPath: $options->outputPath,
            fileCount: count($paths),
            transactionCount: $transactionCount,
            warnings: $warnings,
        );
    }
}
