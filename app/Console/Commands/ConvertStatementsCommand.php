<?php

namespace App\Console\Commands;

use App\Actions\ConvertStatements;
use App\Data\ColumnMapping;
use App\Data\ExportOptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('statements:convert {paths*} {--out=} {--mapping=} {--indian-format=1} {--merge=1} {--summary=1}')]
#[Description('Convert one or more PDF bank statements into an Excel workbook')]
class ConvertStatementsCommand extends Command
{
    public function handle(ConvertStatements $convertStatements): int
    {
        $paths = $this->argument('paths');
        $output = $this->option('out');

        if (! is_string($output) || $output === '') {
            $this->error('The --out option is required.');

            return self::FAILURE;
        }

        $mapping = null;
        $mappingOption = $this->option('mapping');

        if (is_string($mappingOption) && $mappingOption !== '') {
            $json = is_file($mappingOption) ? file_get_contents($mappingOption) : $mappingOption;
            $decoded = json_decode((string) $json, true);

            if (! is_array($decoded)) {
                $this->error('Invalid --mapping JSON.');

                return self::FAILURE;
            }

            $mapping = ColumnMapping::fromArray($decoded);
        }

        $result = $convertStatements->handle(
            paths: $paths,
            options: new ExportOptions(
                outputPath: $output,
                indianFormat: (bool) $this->option('indian-format'),
                mergeIntoOneSheet: (bool) $this->option('merge'),
                includeSummary: (bool) $this->option('summary'),
            ),
            defaultMapping: $mapping,
        );

        $this->info(sprintf(
            'Wrote %s (%d file(s), %d transaction(s)).',
            $result->outputPath,
            $result->fileCount,
            $result->transactionCount,
        ));

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
