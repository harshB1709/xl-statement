<div class="space-y-6">
    <nav class="flex flex-wrap gap-2 text-sm">
        @foreach ([1 => 'Files', 2 => 'Map columns', 3 => 'Export'] as $number => $label)
            <button
                type="button"
                wire:click="$set('step', {{ $number }})"
                @class([
                    'chip',
                    'chip-active' => $step === $number,
                    'chip-idle' => $step !== $number,
                ])
            >
                {{ $number }}. {{ $label }}
            </button>
        @endforeach
    </nav>

    @if ($resultMessage)
        <div @class([
            'rounded-xl px-4 py-3 text-sm ring-1',
            'bg-ok-soft text-ok ring-ok-line' => $resultPath,
            'bg-danger-soft text-danger ring-danger-line' => ! $resultPath,
        ])>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ $resultMessage }}</p>
                @if ($resultPath)
                    <div class="flex gap-2">
                        <button wire:click="openResult" type="button" class="btn-primary !rounded-lg !px-3 !py-1.5">
                            {{ $isNative ? 'Open file' : 'Download' }}
                        </button>
                        @if ($isNative)
                            <button wire:click="showResult" type="button" class="btn-secondary !rounded-lg !px-3 !py-1.5">Show in folder</button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($step === 1)
        <section class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <div class="space-y-4">
                <div class="rounded-2xl border border-dashed border-line-strong bg-surface p-8 text-center">
                    <p class="text-lg font-medium text-ink">Drop PDF statements here</p>
                    <p class="mt-1 text-sm text-muted">
                        @if ($isNative)
                            Or browse with the native file picker. Your files never leave this computer.
                        @else
                            Browser mode via Herd — upload PDFs below. Output saves to <code class="text-xs text-ink">storage/app/exports</code>.
                        @endif
                    </p>
                    @if ($isNative)
                        <button wire:click="browse" type="button" class="btn-primary mt-4">
                            Browse…
                        </button>
                    @else
                        <label class="btn-primary mt-4 cursor-pointer">
                            Choose PDFs…
                            <input wire:model="uploads" type="file" accept="application/pdf,.pdf" multiple class="hidden">
                        </label>
                        <div wire:loading wire:target="uploads" class="mt-2 text-sm text-muted">Uploading…</div>
                    @endif
                </div>

                <div class="panel overflow-hidden">
                    <div class="flex items-center justify-between border-b border-line px-4 py-3 text-sm">
                        <span class="text-muted">{{ count($files) }} file(s) · {{ count($layouts) }} layout(s)</span>
                        <button wire:click="clearAll" type="button" class="btn-ghost">Clear all</button>
                    </div>
                    <ul class="divide-y divide-line">
                        @forelse ($files as $index => $file)
                            <li class="px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-medium text-ink">{{ $file['name'] }}</p>
                                        <p @class([
                                            'text-sm',
                                            'text-warn' => is_string($file['message']) && str_contains($file['message'], 'Incorrect'),
                                            'text-muted' => ! (is_string($file['message']) && str_contains($file['message'], 'Incorrect')),
                                        ])>{{ $file['message'] ?? ucfirst(str_replace('_', ' ', $file['status'])) }}</p>
                                    </div>
                                    <button wire:click="removeFile({{ $index }})" type="button" class="text-sm text-muted transition hover:text-danger">Remove</button>
                                </div>
                                @if ($file['status'] === 'password')
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <input wire:model="files.{{ $index }}.password" type="password" placeholder="PDF password" class="field">
                                        <input wire:model="sharedPassword" type="password" placeholder="Use for all locked" class="field">
                                        <button wire:click="unlock({{ $index }})" type="button" class="rounded-lg bg-warn-soft px-3 py-2 text-sm font-medium text-warn ring-1 ring-warn-line">Unlock</button>
                                    </div>
                                @endif
                            </li>
                        @empty
                            <li class="px-4 py-8 text-center text-sm text-muted">No files yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="panel p-5">
                <h2 class="font-semibold text-ink">Next</h2>
                <p class="mt-2 text-sm text-muted">Extract tables, then map each unique layout once. Saved mappings are reused automatically.</p>
                <button
                    wire:click="goToMapping"
                    type="button"
                    @disabled($layouts === [])
                    class="btn-primary mt-4 w-full"
                >
                    Map columns →
                </button>
            </div>
        </section>
    @endif

    @if ($step === 2)
        <section class="space-y-4">
            <div class="flex flex-wrap gap-2">
                @foreach ($layouts as $fingerprint => $layout)
                    <button
                        type="button"
                        wire:click="setActiveLayout('{{ $fingerprint }}')"
                        @class([
                            'chip',
                            'chip-active' => $activeFingerprint === $fingerprint,
                            'chip-idle' => $activeFingerprint !== $fingerprint,
                        ])
                    >
                        {{ $layout['name'] }} · {{ count($layout['file_paths']) }} file(s)
                    </button>
                @endforeach
            </div>

            @if ($activeFingerprint && isset($layouts[$activeFingerprint]))
                @php($layout = $layouts[$activeFingerprint])
                <div class="grid gap-4 lg:grid-cols-[1fr_280px]">
                    <div class="panel overflow-auto">
                        <div class="border-b border-line px-4 py-3">
                            <input wire:model.live="layouts.{{ $activeFingerprint }}.name" type="text" class="field w-full font-medium">
                        </div>
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-surface-2">
                                <tr>
                                    @foreach ($layout['header_cells'] as $columnIndex => $header)
                                        <th class="px-3 py-3 align-bottom">
                                            <select wire:model.live="layouts.{{ $activeFingerprint }}.targets.{{ $columnIndex }}" class="field mb-2 w-full !rounded-lg !px-2 !py-1.5 text-xs">
                                                @foreach ($this->targetOptions() as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <div class="font-medium text-ink">{{ $header }}</div>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($layout['sample_rows'] as $row)
                                    <tr class="border-t border-line">
                                        @foreach ($row as $cell)
                                            <td class="max-w-56 truncate px-3 py-2 text-muted" title="{{ $cell }}">{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <aside class="panel space-y-4 p-4">
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-muted">Date format</label>
                            <select wire:model.live="layouts.{{ $activeFingerprint }}.date_format" class="field mt-1 w-full">
                                <option value="d/m/Y">dd/mm/yyyy</option>
                                <option value="d-m-Y">dd-mm-yyyy</option>
                                <option value="d-M-Y">dd-Mmm-yyyy</option>
                                <option value="Y-m-d">yyyy-mm-dd</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-muted">Amount style</label>
                            <select wire:model.live="layouts.{{ $activeFingerprint }}.amount_style" class="field mt-1 w-full">
                                <option value="separate_dr_cr">Separate Debit / Credit</option>
                                <option value="single_with_marker">Amount + Dr/Cr</option>
                                <option value="signed_single">Signed amount</option>
                            </select>
                        </div>
                        <div class="rounded-xl bg-surface-2 p-3 text-sm text-ink">
                            <p><span class="font-medium">{{ $layout['transaction_count'] }}</span> transactions</p>
                            <p class="mt-1 text-muted">Balance reconciles on <span class="font-medium text-ink">{{ $layout['reconciliation'] }}%</span></p>
                            <p class="mt-1 text-muted">Extracted with <span class="font-medium text-ink">{{ $layout['text_engine'] ?? 'unknown' }}</span></p>
                        </div>
                        @if ($layout['warnings'] !== [])
                            <div class="max-h-40 overflow-auto rounded-xl bg-warn-soft p-3 text-xs text-warn ring-1 ring-warn-line">
                                @foreach (array_slice($layout['warnings'], 0, 8) as $warning)
                                    <p class="mb-1">{{ $warning }}</p>
                                @endforeach
                            </div>
                        @endif
                        <label class="flex items-center gap-2 text-sm text-ink">
                            <input wire:model="saveProfiles" type="checkbox" class="rounded border-line text-accent focus:ring-accent">
                            Save as profile
                        </label>
                        <button wire:click="goToExport" type="button" class="btn-primary w-full">
                            Next: Export →
                        </button>
                    </aside>
                </div>
            @endif
        </section>
    @endif

    @if ($step === 3)
        <section class="grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
            <div class="panel p-5">
                <h2 class="font-semibold text-ink">Files ready</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($files as $file)
                        @if ($file['fingerprint'])
                            <li class="flex justify-between gap-3">
                                <span class="text-ink">{{ $file['name'] }}</span>
                                <span class="text-muted">{{ $file['row_count'] }} rows</span>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </div>

            <div class="panel space-y-4 p-5">
                <div>
                    <label class="text-sm font-medium text-ink">File name</label>
                    <input wire:model="outputName" type="text" class="field mt-1 w-full">
                </div>
                <div>
                    <label class="text-sm font-medium text-ink">Save to</label>
                    <div class="mt-1 flex gap-2">
                        <input wire:model="outputDirectory" type="text" @disabled(! $isNative) class="field w-full disabled:opacity-70">
                        @if ($isNative)
                            <button wire:click="chooseOutputDirectory" type="button" class="btn-secondary shrink-0">Change…</button>
                        @endif
                    </div>
                </div>

                <details class="rounded-xl bg-surface-2 p-3 text-sm" open>
                    <summary class="cursor-pointer font-medium text-ink">Columns in Excel</summary>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-ink sm:grid-cols-3">
                        @foreach ([
                            'date' => 'Date',
                            'value_date' => 'Value Date',
                            'description' => 'Description',
                            'reference' => 'Ref / Cheque',
                            'debit' => 'Debit',
                            'credit' => 'Credit',
                            'balance' => 'Balance',
                            'bank' => 'Bank',
                            'source_file' => 'Source file',
                            'page' => 'Page',
                        ] as $key => $label)
                            <label class="flex items-center gap-2">
                                <input wire:model="exportColumns.{{ $key }}" type="checkbox" class="rounded border-line text-accent focus:ring-accent">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </details>

                <details class="rounded-xl bg-surface-2 p-3 text-sm">
                    <summary class="cursor-pointer font-medium text-ink">Options</summary>
                    <div class="mt-3 grid gap-2 text-ink">
                        <label class="flex items-center gap-2"><input wire:model="indianFormat" type="checkbox" class="rounded border-line text-accent focus:ring-accent"> Indian lakhs/crores (Excel; avoid in Numbers)</label>
                        <label class="flex items-center gap-2"><input wire:model="mergeIntoOneSheet" type="checkbox" class="rounded border-line text-accent focus:ring-accent"> Merge into one sheet</label>
                        <label class="flex items-center gap-2"><input wire:model="includeSummary" type="checkbox" class="rounded border-line text-accent focus:ring-accent"> Include Summary sheet</label>
                        <label class="flex items-center gap-2"><input wire:model="sortByDate" type="checkbox" class="rounded border-line text-accent focus:ring-accent"> Sort by date</label>
                        <select wire:model="excelDateFormat" class="field">
                            <option value="dd-mm-yyyy">dd-mm-yyyy</option>
                            <option value="dd-mmm-yyyy">dd-mmm-yyyy</option>
                            <option value="yyyy-mm-dd">yyyy-mm-dd</option>
                        </select>
                    </div>
                </details>

                <div class="flex flex-wrap gap-2">
                    <button wire:click="convert" type="button" class="btn-primary">
                        Convert to Excel
                    </button>
                    @if ($isNative)
                        <button wire:click="saveAs" type="button" class="btn-secondary">
                            Save As…
                        </button>
                    @endif
                </div>
            </div>
        </section>
    @endif
</div>
