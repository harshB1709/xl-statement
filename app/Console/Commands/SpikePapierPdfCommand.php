<?php

namespace App\Console\Commands;

use App\Actions\ApplyColumnMapping;
use App\Actions\ExtractStatementTable;
use App\Services\Pdf\PapierPdfTextExtractor;
use App\Services\Pdf\SmalotPdfTextExtractor;
use App\Services\Table\MappingSuggester;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Papier\Elements\Text;
use Papier\Encryption\EncryptionAlgorithm;
use Papier\Encryption\StandardSecurityHandler;
use Papier\PdfDocument;
use Throwable;

#[Signature('statements:spike-papier {--password=} {paths?*}')]
#[Description('Compare Papier vs Smalot PDF extraction (temporary spike)')]
class SpikePapierPdfCommand extends Command
{
    public function handle(
        SmalotPdfTextExtractor $smalot,
        PapierPdfTextExtractor $papier,
        ExtractStatementTable $extractStatementTable,
        MappingSuggester $mappingSuggester,
        ApplyColumnMapping $applyColumnMapping,
    ): int {
        $paths = $this->argument('paths');

        if ($paths === [] || $paths === null) {
            $defaults = [
                base_path('tests/Fixtures/real/axis.pdf'),
                base_path('tests/Fixtures/real/idfc.pdf'),
            ];
            $paths = array_values(array_filter($defaults, 'is_file'));
        }

        if ($paths === []) {
            $this->error('No PDFs found. Pass paths or add files under tests/Fixtures/real/.');

            return self::FAILURE;
        }

        $password = $this->option('password');
        $password = is_string($password) && $password !== '' ? $password : null;

        $this->info('Papier spike — unlocked text quality (no Poppler required)');
        $this->newLine();

        foreach ($paths as $path) {
            $this->line('<fg=cyan>=== '.basename($path).' ===</>');
            $this->compareFile($path, $password, $smalot, $papier, $extractStatementTable, $mappingSuggester, $applyColumnMapping);
            $this->newLine();
        }

        $this->info('Password decrypt check (synthetic AES-256 PDF created by Papier)');
        $this->passwordRoundTrip($papier);

        $this->newLine();
        $this->comment('Verdict guide: if Papier rows/reconcile ≈ Smalot (or better) and password round-trip works, it can replace/fallback Smalot.');
        $this->comment('Poppler is still optional for unlock — only needed if you keep the current PreferPhp→Poppler path.');

        return self::SUCCESS;
    }

    private function compareFile(
        string $path,
        ?string $password,
        SmalotPdfTextExtractor $smalot,
        PapierPdfTextExtractor $papier,
        ExtractStatementTable $extractStatementTable,
        MappingSuggester $mappingSuggester,
        ApplyColumnMapping $applyColumnMapping,
    ): void {
        foreach (['smalot' => $smalot, 'papier' => $papier] as $name => $extractor) {
            try {
                $started = hrtime(true);
                $extracted = $extractor->extract($path, $password);
                $ms = (hrtime(true) - $started) / 1_000_000;

                $table = $extractStatementTable->fromExtractedText($extracted);
                $mapping = $mappingSuggester->suggest($table);
                $parsed = $applyColumnMapping->handle($table, $mapping);

                $sample = mb_substr(preg_replace('/\s+/', ' ', $extracted->pages[0] ?? '') ?? '', 0, 90);

                $this->table(
                    ['metric', $name],
                    [
                        ['pages', (string) count($extracted->pages)],
                        ['non_ws_chars', (string) $extracted->nonWhitespaceLength()],
                        ['extract_ms', number_format($ms, 1)],
                        ['table_rows', (string) count($table->rows)],
                        ['mapped_txns', (string) count($parsed->transactions)],
                        ['reconcile_%', (string) $parsed->reconciliationMatchPercent],
                        ['headers', implode(' | ', $table->headerCells)],
                        ['page1_sample', $sample],
                    ],
                );
            } catch (Throwable $exception) {
                $this->error($name.': '.$exception->getMessage());
            }
        }
    }

    private function passwordRoundTrip(PapierPdfTextExtractor $papier): void
    {
        $path = storage_path('framework/testing/papier-spike-locked.pdf');
        @unlink($path);

        $doc = PdfDocument::create();
        $font = $doc->addFont('Helvetica');
        $page = $doc->addPage();
        $page->add(
            Text::write('14-05-2025 Initial Funding spike amount 25000.00 balance 25000.00')
                ->at(72, 750)
                ->font($font, 12),
        );
        $doc->encrypt('spike-secret', 'owner-secret', StandardSecurityHandler::PERM_ALL, EncryptionAlgorithm::Aes_256);
        $doc->save($path);

        try {
            $papier->extract($path);
            $this->error('Expected password required without password, but extract succeeded.');
        } catch (Throwable $exception) {
            $this->line('no password → '.$exception::class.' (ok if PdfPasswordRequired)');
        }

        try {
            $extracted = $papier->extract($path, 'spike-secret');
            $this->line('with password → pages='.count($extracted->pages).' text_ok='.(str_contains($extracted->fullText(), 'Initial Funding') ? 'yes' : 'no'));
        } catch (Throwable $exception) {
            $this->error('with password failed: '.$exception->getMessage());
        }
    }
}
