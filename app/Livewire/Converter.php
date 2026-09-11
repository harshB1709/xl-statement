<?php

namespace App\Livewire;

use App\Actions\ApplyColumnMapping;
use App\Actions\ConvertStatements;
use App\Actions\ExtractStatementTable;
use App\Data\ColumnMapping;
use App\Data\ExportOptions;
use App\Data\RawColumn;
use App\Data\RawTable;
use App\Enums\AmountStyle;
use App\Enums\TargetField;
use App\Exceptions\PdfHasNoTextLayer;
use App\Exceptions\PdfPasswordRequired;
use App\Models\Conversion;
use App\Models\MappingProfile;
use App\Services\Table\MappingSuggester;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Native\Desktop\Facades\Dialog;
use Native\Desktop\Facades\Shell;
use Throwable;

#[Layout('layouts.app')]
class Converter extends Component
{
    use WithFileUploads;

    public int $step = 1;

    /** @var list<TemporaryUploadedFile>|TemporaryUploadedFile|null */
    public $uploads = [];

    public bool $isNative = false;

    /** @var list<array{path: string, name: string, status: string, message: ?string, fingerprint: ?string, row_count: int, password: string}> */
    public array $files = [];

    /** @var array<string, array{fingerprint: string, name: string, header_cells: list<string>, sample_rows: list<list<string>>, targets: array<string, string>, date_format: string, amount_style: string, file_paths: list<string>, reconciliation: float, warnings: list<string>, transaction_count: int}> */
    public array $layouts = [];

    public string $activeFingerprint = '';

    public string $outputName = '';

    public string $outputDirectory = '';

    public bool $indianFormat = false;

    public bool $mergeIntoOneSheet = true;

    public bool $includeSummary = true;

    public bool $sortByDate = true;

    public bool $includeSourceColumns = true;

    public string $excelDateFormat = 'dd-mm-yyyy';

    /**
     * @var array<string, bool>
     */
    public array $exportColumns = [
        'date' => true,
        'value_date' => true,
        'description' => true,
        'reference' => true,
        'debit' => true,
        'credit' => true,
        'balance' => true,
        'bank' => true,
        'source_file' => true,
        'page' => true,
    ];

    public bool $saveProfiles = true;

    public ?string $resultPath = null;

    public ?string $resultMessage = null;

    public string $sharedPassword = '';

    public function mount(): void
    {
        $this->isNative = $this->nativeDesktopAvailable();
        $this->outputDirectory = $this->defaultOutputDirectory();
        $this->outputName = 'Statements_'.now()->format('Y-m-d').'.xlsx';
    }

    public function browse(): void
    {
        if (! $this->isNative) {
            return;
        }

        try {
            $selected = Dialog::new()
                ->title('Select bank statements')
                ->filter('PDF', ['pdf'])
                ->multiple()
                ->open();
        } catch (Throwable) {
            $this->isNative = false;

            return;
        }

        if ($selected === null) {
            return;
        }

        $paths = is_array($selected) ? $selected : [$selected];

        foreach ($paths as $path) {
            $this->addFile($path);
        }

        $this->extractPending();
    }

    public function updatedUploads(): void
    {
        $uploads = is_array($this->uploads) ? $this->uploads : [$this->uploads];

        foreach ($uploads as $upload) {
            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }

            $this->addFile($upload->getRealPath() ?: $upload->getPathname(), $upload->getClientOriginalName());
        }

        $this->uploads = [];
        $this->extractPending();
    }

    public function chooseOutputDirectory(): void
    {
        if (! $this->isNative) {
            return;
        }

        try {
            $selected = Dialog::new()
                ->title('Choose output folder')
                ->properties(['openDirectory'])
                ->open();
        } catch (Throwable) {
            $this->isNative = false;

            return;
        }

        if (is_string($selected) && $selected !== '') {
            $this->outputDirectory = $selected;
        }
    }

    public function saveAs(): void
    {
        if (! $this->isNative) {
            $this->convert();

            return;
        }

        try {
            $selected = Dialog::new()
                ->title('Save Excel workbook')
                ->button('Save')
                ->filter('Excel', ['xlsx'])
                ->defaultPath($this->fullOutputPath())
                ->save();
        } catch (Throwable) {
            $this->isNative = false;
            $this->convert();

            return;
        }

        if (! is_string($selected) || $selected === '') {
            return;
        }

        $this->outputDirectory = dirname($selected);
        $this->outputName = basename($selected);
        $this->convert();
    }

    public function removeFile(int $index): void
    {
        $path = $this->files[$index]['path'] ?? null;
        unset($this->files[$index]);
        $this->files = array_values($this->files);

        if ($path !== null) {
            foreach ($this->layouts as $fingerprint => $layout) {
                $this->layouts[$fingerprint]['file_paths'] = array_values(array_filter(
                    $layout['file_paths'],
                    static fn (string $filePath): bool => $filePath !== $path,
                ));

                if ($this->layouts[$fingerprint]['file_paths'] === []) {
                    unset($this->layouts[$fingerprint]);
                }
            }
        }
    }

    public function clearAll(): void
    {
        $this->files = [];
        $this->layouts = [];
        $this->activeFingerprint = '';
        $this->step = 1;
        $this->resultPath = null;
        $this->resultMessage = null;
    }

    public function unlock(int $index): void
    {
        $password = $this->files[$index]['password'] !== ''
            ? $this->files[$index]['password']
            : $this->sharedPassword;

        $this->files[$index]['status'] = 'reading';
        $this->files[$index]['message'] = null;
        $this->extractFile($index, $password !== '' ? $password : null, attemptedPassword: true);
    }

    public function goToMapping(): void
    {
        if ($this->layouts === []) {
            return;
        }

        $this->activeFingerprint = array_key_first($this->layouts) ?: '';
        $this->refreshActiveLayoutStats();
        $this->step = 2;
    }

    public function goToExport(): void
    {
        if (! $this->allLayoutsValid()) {
            return;
        }

        $this->step = 2;
    }

    public function downloadExcel(ConvertStatements $convertStatements): mixed
    {
        if ($this->isNative) {
            $this->saveAs();

            return null;
        }

        $this->prepareWebTempOutput();
        $this->convert($convertStatements);

        if ($this->resultPath === null || ! is_file($this->resultPath)) {
            return null;
        }

        return response()
            ->download($this->resultPath, basename($this->resultPath))
            ->deleteFileAfterSend(true);
    }

    public function setActiveLayout(string $fingerprint): void
    {
        if (! isset($this->layouts[$fingerprint])) {
            return;
        }

        $this->activeFingerprint = $fingerprint;
        $this->refreshActiveLayoutStats();
    }

    public function updatedLayouts(): void
    {
        $this->refreshActiveLayoutStats();
    }

    public function convert(ConvertStatements $convertStatements): void
    {
        if (! $this->allLayoutsValid() || $this->outputName === '') {
            return;
        }

        if (! $this->isNative) {
            $this->prepareWebTempOutput();
        } elseif ($this->outputDirectory === '') {
            $this->outputDirectory = $this->defaultOutputDirectory();
        }

        if (array_filter($this->exportColumns) === []) {
            $this->resultPath = null;
            $this->resultMessage = 'Select at least one column to export.';

            return;
        }

        $outputName = str_ends_with(strtolower($this->outputName), '.xlsx')
            ? $this->outputName
            : $this->outputName.'.xlsx';

        $mappings = [];

        foreach ($this->layouts as $fingerprint => $layout) {
            $mapping = $this->mappingFromLayout($layout);
            $mappings[$fingerprint] = $mapping;

            if ($this->saveProfiles) {
                MappingProfile::query()->updateOrCreate(
                    ['fingerprint' => $fingerprint],
                    [
                        'name' => $layout['name'] !== '' ? $layout['name'] : 'Layout '.substr($fingerprint, 0, 6),
                        'mapping' => $mapping->toArray(),
                        'last_used_at' => now(),
                    ],
                );
            } else {
                MappingProfile::query()->where('fingerprint', $fingerprint)->first()?->markUsed();
            }
        }

        $paths = array_values(array_unique(array_map(
            static fn (array $file): string => $file['path'],
            array_filter($this->files, static fn (array $file): bool => ($file['fingerprint'] ?? null) !== null),
        )));

        $pathLabels = [];
        $passwords = [];

        foreach ($this->files as $file) {
            if (($file['fingerprint'] ?? null) === null) {
                continue;
            }

            $pathLabels[$file['path']] = $file['name'];

            $password = $file['password'] !== ''
                ? $file['password']
                : $this->sharedPassword;

            if ($password !== '') {
                $passwords[$file['path']] = $password;
            }
        }

        try {
            $result = $convertStatements->handle(
                paths: $paths,
                options: new ExportOptions(
                    outputPath: $this->outputDirectory.DIRECTORY_SEPARATOR.$outputName,
                    indianFormat: $this->indianFormat,
                    mergeIntoOneSheet: $this->mergeIntoOneSheet,
                    includeSummary: $this->includeSummary,
                    sortByDate: $this->sortByDate,
                    includeSourceColumns: ($this->exportColumns['source_file'] ?? false)
                        || ($this->exportColumns['page'] ?? false),
                    excelDateFormat: $this->excelDateFormat,
                    columns: array_values(array_keys(array_filter($this->exportColumns))),
                ),
                mappingsByFingerprint: $mappings,
                passwords: $passwords,
                pathLabels: $pathLabels,
            );

            Conversion::query()->create([
                'output_path' => $result->outputPath,
                'file_count' => $result->fileCount,
                'transaction_count' => $result->transactionCount,
                'status' => 'completed',
                'warnings' => $result->warnings,
            ]);

            $this->resultPath = $result->outputPath;
            $this->resultMessage = sprintf(
                'Ready %s · %d rows',
                basename($result->outputPath),
                $result->transactionCount,
            );
            $this->step = 2;
        } catch (Throwable $exception) {
            $this->resultPath = null;
            $this->resultMessage = $exception->getMessage();
        }
    }

    public function openResult(): void
    {
        if (! $this->resultPath) {
            return;
        }

        if ($this->isNative) {
            try {
                Shell::openFile($this->resultPath);

                return;
            } catch (Throwable) {
                $this->isNative = false;
            }
        }

        if (! is_file($this->resultPath)) {
            return;
        }

        $this->redirect(route('exports.download', ['file' => basename($this->resultPath)]));
    }

    public function showResult(): void
    {
        if (! $this->resultPath || ! $this->isNative) {
            return;
        }

        try {
            Shell::showInFolder($this->resultPath);
        } catch (Throwable) {
            $this->isNative = false;
        }
    }

    public function targetOptions(): array
    {
        $options = [];

        foreach (TargetField::cases() as $field) {
            $options[$field->value] = $field->label();
        }

        return $options;
    }

    public function render()
    {
        return view('livewire.converter');
    }

    private function addFile(string $path, ?string $displayName = null): void
    {
        foreach ($this->files as $file) {
            if ($file['path'] === $path) {
                return;
            }
        }

        $this->files[] = [
            'path' => $path,
            'name' => $displayName ?? basename($path),
            'status' => 'queued',
            'message' => null,
            'fingerprint' => null,
            'row_count' => 0,
            'password' => '',
        ];
    }

    private function nativeDesktopAvailable(): bool
    {
        foreach (['NATIVEPHP_RUNNING', 'NATIVEPHP'] as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

            if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
                return true;
            }
        }

        return (bool) config('nativephp-internal.running', false);
    }

    private function extractPending(): void
    {
        foreach ($this->files as $index => $file) {
            if ($file['status'] === 'queued') {
                $this->extractFile($index);
            }
        }

        if ($this->layouts !== [] && $this->allHaveProfiles()) {
            $this->step = 2;
        }
    }

    private function extractFile(int $index, ?string $password = null, bool $attemptedPassword = false): void
    {
        $path = $this->files[$index]['path'];
        $this->files[$index]['status'] = 'reading';

        try {
            /** @var ExtractStatementTable $extractor */
            $extractor = app(ExtractStatementTable::class);
            $table = $extractor->handle($path, $password);
            if ($password !== null && $password !== '') {
                $this->files[$index]['password'] = $password;
            }
            $this->ingestTable($index, $table);
        } catch (PdfPasswordRequired) {
            $this->files[$index]['status'] = 'password';
            $this->files[$index]['message'] = $attemptedPassword && ($password !== null && $password !== '')
                ? 'Incorrect password. Try again.'
                : 'Password required';
        } catch (PdfHasNoTextLayer $exception) {
            $this->files[$index]['status'] = 'failed';
            $this->files[$index]['message'] = $exception->getMessage();
        } catch (Throwable $exception) {
            $this->files[$index]['status'] = 'failed';
            $this->files[$index]['message'] = $exception->getMessage();
        }
    }

    private function ingestTable(int $index, RawTable $table): void
    {
        $profile = MappingProfile::query()->where('fingerprint', $table->layoutFingerprint)->first();
        $suggested = $profile?->columnMapping() ?? app(MappingSuggester::class)->suggest($table);

        if (! isset($this->layouts[$table->layoutFingerprint])) {
            $targets = [];

            foreach ($suggested->targets as $columnIndex => $target) {
                $targets[(string) $columnIndex] = $target->value;
            }

            $this->layouts[$table->layoutFingerprint] = [
                'fingerprint' => $table->layoutFingerprint,
                'name' => $profile?->name ?? ($table->bankName ? $table->bankName.' layout' : 'Layout '.substr($table->layoutFingerprint, 0, 6)),
                'header_cells' => $table->headerCells,
                'sample_rows' => array_slice($table->rows, 0, 15),
                'targets' => $targets,
                'date_format' => $suggested->dateFormat,
                'amount_style' => $suggested->amountStyle->value,
                'file_paths' => [],
                'reconciliation' => 0,
                'warnings' => [],
                'transaction_count' => 0,
                'text_engine' => $table->textEngine,
                'raw_table' => [
                    'layoutFingerprint' => $table->layoutFingerprint,
                    'headerCells' => $table->headerCells,
                    'columns' => array_map(static fn ($column) => [
                        'index' => $column->index,
                        'headerText' => $column->headerText,
                        'xStart' => $column->xStart,
                        'xEnd' => $column->xEnd,
                        'sampleValues' => $column->sampleValues,
                    ], $table->columns),
                    'rows' => $table->rows,
                    'pageOf' => $table->pageOf,
                    'sourceFile' => $table->sourceFile,
                    'bankName' => $table->bankName,
                    'rawPreamble' => $table->rawPreamble,
                    'displayName' => $this->files[$index]['name'],
                    'textEngine' => $table->textEngine,
                ],
            ];
        }

        $this->layouts[$table->layoutFingerprint]['file_paths'][] = $table->sourceFile;
        $this->files[$index]['fingerprint'] = $table->layoutFingerprint;
        $this->files[$index]['row_count'] = count($table->rows);
        $this->files[$index]['status'] = $profile ? 'mapped' : 'table_found';
        $this->files[$index]['message'] = $profile
            ? 'Mapped using "'.$profile->name.'"'
            : count($table->rows).' rows · '.$table->textEngine.' · needs mapping';

        if ($this->activeFingerprint === '') {
            $this->activeFingerprint = $table->layoutFingerprint;
        }

        $this->refreshLayoutStats($table->layoutFingerprint);
    }

    private function refreshActiveLayoutStats(): void
    {
        if ($this->activeFingerprint !== '') {
            $this->refreshLayoutStats($this->activeFingerprint);
        }
    }

    private function refreshLayoutStats(string $fingerprint): void
    {
        if (! isset($this->layouts[$fingerprint]['raw_table'])) {
            return;
        }

        $raw = $this->layouts[$fingerprint]['raw_table'];
        $table = new RawTable(
            layoutFingerprint: $raw['layoutFingerprint'],
            headerCells: $raw['headerCells'],
            columns: array_map(static fn (array $column) => new RawColumn(
                index: $column['index'],
                headerText: $column['headerText'],
                xStart: $column['xStart'],
                xEnd: $column['xEnd'],
                sampleValues: $column['sampleValues'],
            ), $raw['columns']),
            rows: $raw['rows'],
            pageOf: $raw['pageOf'],
            sourceFile: $raw['sourceFile'],
            bankName: $raw['bankName'],
            rawPreamble: $raw['rawPreamble'],
            displayName: $raw['displayName'] ?? null,
            textEngine: $raw['textEngine'] ?? 'unknown',
        );

        $mapping = $this->mappingFromLayout($this->layouts[$fingerprint]);
        $parsed = app(ApplyColumnMapping::class)->handle($table, $mapping);

        $this->layouts[$fingerprint]['reconciliation'] = $parsed->reconciliationMatchPercent;
        $this->layouts[$fingerprint]['warnings'] = $parsed->warnings;
        $this->layouts[$fingerprint]['transaction_count'] = count($parsed->transactions);
    }

    /**
     * @param  array{targets: array<string, string>, date_format: string, amount_style: string, name?: string}  $layout
     */
    private function mappingFromLayout(array $layout): ColumnMapping
    {
        $targets = [];

        foreach ($layout['targets'] as $index => $value) {
            $targets[(int) $index] = TargetField::from($value);
        }

        return new ColumnMapping(
            targets: $targets,
            dateFormat: $layout['date_format'],
            amountStyle: AmountStyle::from($layout['amount_style']),
            profileName: $layout['name'] ?? null,
        );
    }

    public function allLayoutsValid(): bool
    {
        foreach ($this->layouts as $layout) {
            $targets = array_values($layout['targets']);
            $hasDate = in_array(TargetField::Date->value, $targets, true);
            $hasDescription = in_array(TargetField::Description->value, $targets, true);
            $hasBalance = in_array(TargetField::Balance->value, $targets, true);
            $hasAmounts = (in_array(TargetField::Debit->value, $targets, true) && in_array(TargetField::Credit->value, $targets, true))
                || (in_array(TargetField::Amount->value, $targets, true) && in_array(TargetField::DrCrMarker->value, $targets, true))
                || in_array(TargetField::Amount->value, $targets, true);

            if (! $hasDate || ! $hasDescription || ! $hasBalance || ! $hasAmounts) {
                return false;
            }
        }

        return $this->layouts !== [];
    }

    private function allHaveProfiles(): bool
    {
        foreach ($this->layouts as $fingerprint => $layout) {
            if (! MappingProfile::query()->where('fingerprint', $fingerprint)->exists()) {
                return false;
            }
        }

        return $this->layouts !== [];
    }

    private function fullOutputPath(): string
    {
        $name = str_ends_with(strtolower($this->outputName), '.xlsx')
            ? $this->outputName
            : $this->outputName.'.xlsx';

        return rtrim($this->outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
    }

    private function prepareWebTempOutput(): void
    {
        $directory = storage_path('framework/tmp');
        File::ensureDirectoryExists($directory);
        $this->outputDirectory = $directory;
    }

    private function defaultOutputDirectory(): string
    {
        if (! $this->isNative) {
            $this->prepareWebTempOutput();

            return $this->outputDirectory;
        }

        foreach (['NATIVEPHP_DOWNLOADS_PATH', 'NATIVEPHP_USER_HOME_PATH'] as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($key === 'NATIVEPHP_DOWNLOADS_PATH' && File::isDirectory($value)) {
                return $value;
            }

            if ($key === 'NATIVEPHP_USER_HOME_PATH') {
                $downloads = rtrim($value, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'Downloads';

                if (File::isDirectory($downloads)) {
                    return $downloads;
                }

                if (File::isDirectory($value)) {
                    return $value;
                }
            }
        }

        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? null;

        if (is_string($home) && $home !== '') {
            $downloads = $home.DIRECTORY_SEPARATOR.'Downloads';

            if (File::isDirectory($downloads)) {
                return $downloads;
            }

            return $home;
        }

        $this->prepareWebTempOutput();

        return $this->outputDirectory;
    }
}
