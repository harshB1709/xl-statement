<?php

namespace App\Console\Commands;

use App\Services\Pdf\PopplerTextExtractor;
use Illuminate\Console\Command;

class DiagnosePopplerCommand extends Command
{
    protected $signature = 'xl:diagnose-poppler';

    protected $description = 'Show where XL Statement looks for pdftotext (NativePHP extras, PATH, etc.)';

    public function handle(PopplerTextExtractor $extractor): int
    {
        $diagnosis = $extractor->diagnose();

        $this->line('NATIVEPHP_EXTRAS_PATH: '.($diagnosis['extras_env'] ?? '(not set)'));
        $this->line('base_path: '.base_path());
        $this->line('PHP_BINARY: '.(PHP_BINARY !== '' ? PHP_BINARY : '(empty)'));
        $this->newLine();

        $this->table(
            ['Usable', 'Path'],
            collect($diagnosis['checked'])->map(fn (array $row): array => [
                $row['usable'] ? 'yes' : 'no',
                $row['path'],
            ])->all(),
        );

        if ($diagnosis['available']) {
            $this->info('Resolved binary: '.$diagnosis['binary']);

            return self::SUCCESS;
        }

        $this->error('No usable pdftotext found.');
        $this->warn('`where pdftotext` / `which pdftotext` can be empty — that is normal. Packaged apps use extras/win next to the .exe, not PATH.');
        $this->warn('If extras/win/pdftotext.exe is ~130 bytes, run: git lfs install && git lfs pull');

        return self::FAILURE;
    }
}
