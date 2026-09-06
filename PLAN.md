# xl-statement — Plan

Windows desktop app (NativePHP + Laravel 13) that takes one or more PDF bank statements and writes a single `.xlsx` workbook. The user picks the output filename and folder. Amounts are real numbers with Indian digit grouping (`12,34,567.00`).

This document is the spec/handoff for whoever builds it. Sections marked **VERIFY** are assumptions that must be confirmed during the M0 spike before building on them.

---

## 1. Current state of the repo

- Bare Laravel 13 (`laravel/framework ^13.17`), PHP `^8.3`, Pest 5, Pint, Boost.
- Frontend: Vite 8 + Tailwind v4 already wired (`@tailwindcss/vite`). No Livewire, no NativePHP yet.
- SQLite default DB.
- Not a git repo yet (`git init` first).
- `.ai/rules` does not exist yet. Record decisions there with Boost `record-rule` as they are made.

## 2. Stack decisions

| Concern | Decision | Why |
|---|---|---|
| Desktop shell | `nativephp/desktop ^2` (Electron runtime) | Only NativePHP package that supports Laravel 13. Namespace is `Native\Desktop\…` (not `Native\Laravel`). Dev command is `php artisan native:run`. |
| UI | Livewire 4 + Blade + Tailwind v4 + Alpine | Livewire 4.2+ supports L13. NativePHP facades (`Dialog`, `Shell`, `Settings`) are synchronous PHP calls, which fit Livewire actions naturally. No API layer needed. |
| PDF text extraction | Bundle Poppler `pdftotext.exe` in `extras/win/` and call it via `Illuminate\Support\Facades\Process` | Indian bank statements are very often password-protected (emailed statements). `smalot/pdfparser` cannot decrypt (`Secured pdf file are currently not supported`). `pdftotext -upw <pw> -layout` handles encryption and preserves column alignment, which is what the parsers key on. `-tsv` mode gives per-word bounding boxes if a column-geometry parser is needed later. |
| Excel writing | Hand-rolled minimal XLSX writer (`app/Services/Excel/XlsxWriter.php`) using `ZipArchive` + string-built XML | **The NativePHP static PHP binary does not ship `xmlreader`/`xmlwriter`** (ext list: `bcmath,bz2,ctype,curl,dom,fileinfo,filter,gd,iconv,intl,mbstring,mbregex,opcache,openssl,pdo,pdo_sqlite,phar,session,simplexml,sockets,sodium,sqlite3,tokenizer,xml,zip,zlib`). PhpSpreadsheet requires both; OpenSpout requires `xmlreader`. Composer's platform check will refuse to boot under the bundled PHP. An XLSX with 2 sheets, styles, number formats, column widths, freeze pane and autofilter is ~6 small XML files; writing it directly is less work than maintaining a custom static PHP build. |
| Persistence | SQLite (Eloquent) for conversion history; NativePHP `Settings` facade for preferences | `Settings` is a JSON file in appdata; good for last output folder, format toggles. |
| Target | Windows 10+ x64 (primary). macOS build is a free by-product for dev only. | Dev machine is macOS: `native:run` works there, but `pdftotext` must be resolved per-OS (`extras/mac/pdftotext` via `brew install poppler` for local dev, `extras/win/pdftotext.exe` shipped). |

### VERIFY in M0 (do these before anything else)

1. `composer require nativephp/desktop && php artisan native:install`, then run the bundled binary with `-m` (`vendor/nativephp/desktop/resources/js/resources/php/php -m` or the Windows equivalent) and confirm `xmlwriter` is indeed absent. If it is present after all, PhpSpreadsheet becomes an option (but the hand-rolled writer is still smaller and fully controllable).
2. Confirm `extras/` folder is copied into the build and how NativePHP exposes its path at runtime (there is an `extras` storage disk; confirm `Storage::disk('extras')->path('win/pdftotext.exe')` resolves inside a packaged app and in `native:run`).
3. Confirm `Process::run([$pdftotext, '-layout', '-upw', $pw, $pdf, '-'])` works from the bundled PHP on Windows (path with spaces, output as UTF-8).
4. Confirm `Dialog::new()->multiple()->filter('PDF', ['pdf'])->open()` returns an array of absolute paths and `Dialog::new()->properties(['openDirectory'])->open()` returns a folder path.
5. Confirm Livewire 4 renders inside the NativePHP window with Vite assets (`npm run build` must be run before `native:build`).

## 3. Architecture

```
app/
  Actions/
    ExtractStatementTable.php      # PDF → RawTable (columns + rows of raw strings), no semantics
    ApplyColumnMapping.php         # RawTable + ColumnMapping → ParsedStatement (typed rows + warnings)
    ConvertStatements.php          # orchestrates all files → workbook → write
  Data/                            # readonly DTOs (plain PHP, no package)
    RawTable.php                   # layoutFingerprint, headerCells[], columns[] (index, headerText, xStart, xEnd, sampleValues[]), rows[][], pageOf[]
    ColumnMapping.php              # array<int columnIndex, TargetField> + dateFormat + amountStyle (SeparateDrCr | SingleWithMarker | SignedSingle)
    TargetField.php                # enum: Date, ValueDate, Description, Reference, Debit, Credit, Amount, DrCrMarker, Balance, AppendToDescription, KeepAsExtra, Ignore
    Transaction.php                # date, valueDate, description, reference, debit, credit, balance, extras[], page, sourceFile
    ParsedStatement.php            # bank?, accountNumberMasked?, periodFrom, periodTo, openingBalance?, closingBalance?, transactions[], warnings[]
    ExportOptions.php              # outputPath, indianFormat, mergeIntoOneSheet, includeSummary, sortByDate, sourceColumn
    ConversionResult.php
  Services/
    Pdf/
      PdfTextExtractor.php         # interface: extract(string $path, ?string $password): ExtractedText
      PopplerTextExtractor.php     # runs pdftotext -layout (and -tsv when needed); throws PdfPasswordRequired / PdfHasNoTextLayer / PdfExtractionFailed
      ExtractedText.php            # pages[] of text; optional word boxes from -tsv
    Table/
      HeaderRowFinder.php          # finds the transaction table header line on each page (score lines by header-synonym hits + repeated on ≥2 pages)
      ColumnBoundaryDetector.php   # header word positions + whitespace-gap histogram over body lines → x-ranges per column
      RowSlicer.php                # slices each body line by x-ranges; joins continuation lines (no date, no amount) into the previous row
      NoiseFilter.php              # drops page footers/headers, "Page x of y", disclaimers, repeated header rows, totals rows
      LayoutFingerprint.php        # normalised header text + column count + bank-name sniff → stable hash for auto-mapping reuse
      MappingSuggester.php         # proposes a ColumnMapping from header synonyms + value shapes (date-like, amount-like, monotonic balance)
    Statements/
      IndianAmount.php             # "1,23,456.78", "1,23,456.78 Cr", "(1,234.00)", "12,345.00 Dr", "-1,234" → float + optional Dr/Cr
      DateNormalizer.php           # 01/04/25, 01-Apr-2025, 01 Apr 2025, 2025-04-01 → CarbonImmutable; detects dd/mm vs mm/dd from the whole column
      BalanceReconciler.php        # verifies prev balance ± amount = balance; flags rows and reports a match %
      MetadataSniffer.php          # best-effort account no (mask), period, opening/closing balance from non-table text; all optional
    Excel/
      XlsxWriter.php               # low-level: sheets, cells (string/number/date/formula), styles, widths, freeze, autofilter
      NumberFormats.php            # Indian + standard format codes
      StatementWorkbookBuilder.php # ParsedStatement[] + ExportOptions → XlsxWriter calls
  Livewire/
    Converter.php                  # main screen: files → mapping → output
    ColumnMapper.php               # the mapping panel (child component), one per layout group
    Settings.php                   # preferences panel/modal
  Models/
    MappingProfile.php             # saved mappings: fingerprint, name, mapping json, times_used, last_used_at
    Conversion.php                 # history: created_at, output_path, file_count, txn_count, status
  Providers/
    NativeAppServiceProvider.php   # window config (published by native:install)
  Console/Commands/
    ConvertStatementsCommand.php   # `php artisan statements:convert a.pdf b.pdf --out=x.xlsx` — same pipeline, headless; used for dev + tests
extras/
  win/pdftotext.exe (+ required DLLs from poppler-windows release)
  mac/pdftotext (dev only, optional; can also fall back to `which pdftotext`)
tests/
  Fixtures/statements/            # SYNTHETIC text dumps + synthetic PDFs only. Never commit real statements.
```

Principles:
- **No bank-specific code.** The app extracts a table (columns of raw strings) and the user tells it what each column means. Bank knowledge lives only in saved `MappingProfile` rows that the user created, auto-applied when the same layout shows up again.
- Table code operates on **text**, not PDFs. Fixtures are the `pdftotext -layout` output saved as `.txt`, so tests run without the binary.
- `PdfTextExtractor` is an interface so a pure-PHP fallback (`smalot/pdfparser` + `tecnickcom/tc-lib-pdf-encrypt`) can be swapped in later without touching table extraction.
- One file at a time per Livewire action (`parseNext()`), driven from the frontend, so the UI can show per-file progress without queue workers. PHP requests are synchronous; a 200-page PDF is still sub-second through `pdftotext`.
- Never load user PDFs into `storage/`. Read them in place from the paths the dialog returns. Output goes only where the user chose.
- Passwords are held in the Livewire component for the session only. Never persisted, never logged.

## 4. Extraction + mapping strategy

There is no standard Indian bank statement format; every bank (and often every channel within a bank) has its own layout. What they share is a table shape: Date · (Value Date) · Narration · Ref/Chq · Withdrawal · Deposit · Balance, or a single Amount column with a `Dr`/`Cr` marker. So the app is split in two: a **bank-agnostic table extractor** that produces columns of raw strings, and a **user-driven column mapping** that assigns meaning. Support for "all banks" comes from the mapping step, not from parser code.

### 4.1 Table extraction (automatic, no semantics)

Per file:
1. `pdftotext -layout` (retry with `-upw` on encryption → `PdfPasswordRequired` so the UI asks).
2. If output has < N non-whitespace chars per page → `PdfHasNoTextLayer` (scanned). Tell the user; OCR is out of scope for v0.
3. `HeaderRowFinder`: score every line by hits against header synonyms (`date|txn date|value date|narration|description|particulars|details|remarks|chq|cheque|ref|utr|withdrawal|debit|dr|deposit|credit|cr|amount|balance|closing`). The best-scoring line that repeats on ≥2 pages (or is the only candidate on a 1-page statement) is the header. Its position on each page marks where the table body starts.
4. `ColumnBoundaryDetector`: header word start/end positions give seed boundaries; refine them with a whitespace-gap histogram over the body lines (columns are where nearly every line has spaces). Handle a wrapped two-line header (e.g. `Withdrawal` over `Amount`) by merging header lines that sit directly above the first dated row. Fall back to `-tsv` word boxes when `-layout` alignment is unreliable (right-aligned amounts overflowing into the neighbouring column).
5. `RowSlicer`: slice each body line into cells by the boundaries. A line whose date cell is empty and whose amount cells are empty is a continuation → append to the previous row's text cells. Keep `page` for every row.
6. `NoiseFilter`: drop repeated header rows on later pages, "Page x of y", disclaimers, "Opening Balance"/"Closing Balance"/"Total" rows (kept aside as metadata candidates, not transactions).
7. `LayoutFingerprint`: `sha1(normalised header cells + column count + sniffed bank name)`. Files with the same fingerprint are grouped so the user maps once per layout, not once per file.
8. Output `RawTable`: columns with header text + first 5 non-empty sample values (for the mapping UI), and rows of raw strings.

### 4.2 Column mapping (user-driven, auto-suggested)

`MappingSuggester` proposes a `ColumnMapping` per layout group:
- header synonyms → target field (`Withdrawal`→Debit, `Deposit`→Credit, `Narration`→Description, `Chq./Ref.No.`→Reference, `Balance`→Balance, …).
- value shape checks override/confirm: a column where >90% of values parse as dates → Date; values parsing as amounts and forming a monotonic-ish running series consistent with the other amount columns → Balance; a column of only `Dr`/`Cr`/`D`/`C` → DrCrMarker.
- amount style inferred: two amount columns mostly mutually exclusive → `SeparateDrCr`; one amount column + marker column → `SingleWithMarker`; one amount column with signs/parentheses → `SignedSingle`.
- date format inferred from the whole column (`dd/mm` if any day > 12, else assume `dd/mm` for India and flag as assumption).

If a saved `MappingProfile` exists for the fingerprint, apply it and skip the mapping UI unless the user opens it (show "Mapped automatically using 'HDFC netbanking' · Edit").

The user sees the mapping panel (section 6) and can change any target. Required before export: Date, Description, Balance, and either (Debit + Credit) or (Amount + DrCrMarker) or (Amount signed). Everything else is optional. Extra columns can be `AppendToDescription`, `KeepAsExtra` (becomes an extra Excel column with the PDF header as its name), or `Ignore`.

### 4.3 Typing + validation (`ApplyColumnMapping`)

- `IndianAmount` parses `1,23,456.78`, `1,23,456.78 Cr`, `12,345.00 Dr`, `(1,234.00)`, `-1,234`, `₹`/`INR`/`Rs.` prefixes.
- `DateNormalizer` parses with the mapping's date format; rows whose date fails to parse are flagged, not dropped.
- `BalanceReconciler` walks rows: `prev_balance + credit − debit ≈ balance` (tolerance 0.01). Reports match %. If swapping Debit/Credit makes ≥95% match, tell the user ("Debit and Credit look swapped — swap?") rather than silently fixing. Mismatched rows get a warning with page and raw line.
- `MetadataSniffer` best-effort from non-table text: account number (mask to last 4), statement period, opening/closing balance. All optional; never blocks export.
- Result: `ParsedStatement` with `transactions[]` and `warnings[]`. The mapping panel shows live stats from this step (rows, date range, reconciliation %) so the user gets immediate feedback when they change a mapping.

Deliberately out of scope for v0: OCR, image-only PDFs, multi-currency, statements where the table is not a single left-to-right grid (e.g. two-column receipts).

## 5. Output workbook spec

Sheet `Transactions` (or one sheet per file when `mergeIntoOneSheet = false`, named after the file, ≤31 chars, deduped):

| Col | Header | Type | Format |
|---|---|---|---|
| A | Date | Excel date serial | `dd-mm-yyyy` |
| B | Value Date | date (blank if same/absent) | `dd-mm-yyyy` |
| C | Description | string | wrap off, width ~60 |
| D | Ref / Cheque No | string (keep leading zeros) | text |
| E | Debit | number or blank | Indian format |
| F | Credit | number or blank | Indian format |
| G | Balance | number | Indian format |
| H | Bank | string | |
| I | Account | string (masked `XXXX1234`) | |
| J | Source File | string | |
| K | Page | int | |

- Row 1 header: bold, fill, frozen (`ySplit=1`), autofilter on the used range.
- Totals row after data: `=SUBTOTAL(9, E2:E{n})` for Debit and Credit so filtered totals update.
- Sort by Date then original order when `sortByDate = true` (default true when merging multiple files, false for single file to preserve statement order).

Sheet `Summary` (when `includeSummary = true`, default on): one row per input file — File, Bank, Account, Period From, Period To, Opening Balance, Total Debit, Total Credit, Closing Balance, Computed Closing (`=F+H−G`), Reconciles? (`✓`/`✗`), Transactions, Warnings count.

Optional sheet `Warnings`: file, page, line text, reason — only if any warnings exist.

### Indian number format

Excel custom format (works in Excel, LibreOffice, Google Sheets):

```
[>=10000000]##\,##\,##\,##0.00;[>=100000]##\,##\,##0.00;##,##0.00
```

- Conditions consume the positive/negative sections, so this format has no dedicated negative section. Keep Debit and Credit as separate positive columns (which matches how statements are laid out). Balance can be negative (overdraft); it falls into the third section and renders as `-1,500,000.00` (standard grouping). Acceptable for v0; note it in the UI tooltip. If needed later, write negatives to a second style with `[<=-10000000]-##\,##\,##\,##0.00;[<=-100000]-##\,##\,##0.00;-##,##0.00` chosen per cell (the writer knows the value, so it can pick the style id).
- Setting `indianFormat = false` uses `#,##0.00`.
- Values are stored as real numbers so SUM/pivots work. Never write amounts as strings.
- Optional variant with symbol: prefix `[$₹-4009]` to each section.

## 6. UI

Single window, ~960×680 min 800×600, `rememberState()`, native title bar, hidden menu bar (keep `Ctrl+Q`, `Ctrl+O`, `Ctrl+,` accelerators via NativePHP `Menu`). Light + dark following OS (`prefers-color-scheme`, Tailwind `dark:`). Fonts: system UI stack. Density: compact; this is a utility, not a marketing page.

### Screen: Converter (the only main screen)

Three steps shown as a slim stepper across the top: **1 Files → 2 Map columns → 3 Export**. Steps 1 and 3 live in a two-column layout (input left, output right); step 2 takes the full width when active. The user can jump back at any time.

**Step 1 — Files (left column)**
- Drop zone card (dashed border) with icon, "Drop PDF statements here" and a **Browse…** button (`Dialog::new()->title('Select bank statements')->filter('PDF', ['pdf'])->multiple()->open()`).
  - Drag-and-drop: Electron gives `File` objects but no path in the renderer. v0: treat a drop as a Livewire file upload (`wire:model` on a hidden input) which copies into a temp dir, then delete after conversion. Native paths via `webUtils.getPathForFile` in a preload script is a v1 improvement (requires `native:install --publish`).
- File list (table-ish rows): PDF icon · filename · size · pages · **layout badge** (e.g. "Layout A · 7 columns", or the saved profile name like "HDFC netbanking" when a `MappingProfile` matched) · status chip · remove ×.
  - Status chips: `Queued` (grey) · `Reading…` (spinner) · `🔒 Password` (amber, inline password field + "Unlock" appears in the row; checkbox "use for all locked files") · `Table found · 128 rows` (blue, needs mapping) · `Mapped · 128 txns` (green) · `Mapped · 12 txns, 3 warnings` (amber, click to expand) · `Failed` (red, tooltip: scanned PDF / no table found / corrupt).
  - Footer: "3 files · 2 layouts · 412 rows" · "Clear all".
- Files with the same layout fingerprint are visually grouped (subtle group header "Layout A — 2 files") so the user understands they'll map once per group.
- **Next: Map columns →** button (enabled once ≥1 file has a table). Skipped automatically if every group already has a saved profile; the user can still open step 2 via the badge.

**Step 2 — Map columns (full width, one group at a time with tabs per layout group)**

The core screen of the app. Goal: the user should be able to confirm a correct auto-suggestion in one click, and fix a wrong one in a few.

- Top bar: layout group name (editable, becomes the saved profile name) · "2 files use this layout" · tabs for other groups · **Reset to suggested**.
- Main area: a **preview grid** of the raw table, first ~15 rows (scrollable, more on demand), with the PDF header text as the column caption. Above each column caption sits a **target dropdown**:
  `Date · Value Date · Description · Reference · Debit · Credit · Amount · Dr/Cr marker · Balance · Append to Description · Keep as extra column · Ignore`.
  Suggested targets are pre-selected; a small "suggested" dot marks any that came from heuristics rather than a saved profile.
- Column header colour follows the target (dates blue, amounts green/red, balance purple, ignored grey) so the mapping is readable at a glance.
- Clicking a cell shows the raw text and the page number; useful for spotting continuation-line joins gone wrong.
- Right rail (or bottom bar on narrow windows) — **live validation**, recomputed on every change:
  - Required fields checklist: Date ✓ · Description ✓ · Balance ✓ · Debit/Credit ✓ (or Amount + marker). Missing ones are red and the Next button is disabled.
  - "Date format: dd/mm/yyyy (detected)" with an override select; shows first/last date.
  - "Balance reconciles on 127 of 128 rows (99%)" with a link to view the mismatching rows. Below 90% show a hint: "Debit and Credit may be swapped — **Swap**".
  - Row count after noise filtering; count of rows that failed to parse (click to view).
- Bottom: **Save as profile** checkbox (on by default, named from the top bar) · **← Back** · **Next: Export →**.
- Advanced disclosure (hidden by default): adjust column boundaries by dragging dividers on the preview grid (v1); mark a row as "header" or "noise" to teach the filter (v1).

**Step 3 — Export (right column, with the file list still visible on the left)**
- **File name** input, default `Statements_<from>_<to>.xlsx` (or `<pdfname>.xlsx` for one file). Auto-append `.xlsx`. Validate Windows-illegal characters `\ / : * ? " < > |`.
- **Save to** row: truncated folder path + **Change…** (`Dialog::new()->properties(['openDirectory'])->open()`). Default: last used, else the folder of the first PDF, else Downloads. Persist with `Settings::set('output_dir', …)`.
- If `<folder>/<name>.xlsx` exists: inline warning "File exists — will overwrite" + toggle to auto-suffix `(1)`.
- **Options** (collapsed "Options" disclosure, remembered via `Settings`):
  - Indian number format (on)
  - One sheet per file / Merge into one sheet (merge)
  - Include Summary sheet (on)
  - Sort merged rows by date (on)
  - Date format: `dd-mm-yyyy` / `dd-mmm-yyyy` / `yyyy-mm-dd`
  - Include Source File & Page columns (on)
- Primary button **Convert to Excel** (`Ctrl+Enter`), disabled until every included file is mapped and name/folder valid. Secondary: **Save As…** (single `Dialog::save()` that sets both name and folder).
- Compact **Preview** of the merged output (first 10 typed rows, already formatted as they will appear) so the user sees the final shape before writing.

**States**
- Empty: drop zone centred, output column dimmed.
- Working: progress bar per file, overall `2 of 5`, Convert button becomes Cancel (cancel between files, not mid-file).
- Done: green banner "Saved Statements_2025-26.xlsx · 412 rows" with **Open file** (`Shell::openFile`), **Show in folder** (`Shell::showInFolder`), **Convert more**. Fire a system `Notification` if the window isn't focused.
- Error writing: "Excel has this file open — close it and retry" (catch `fopen`/`ZipArchive` failure on Windows file lock), or permission denied → offer Save As.

**Other UI**
- Settings modal (`Ctrl+,`): defaults for the options above, default output folder, "Reset".
- **Saved profiles** panel (in Settings or its own drawer): list of `MappingProfile`s with name, column count, times used, last used; rename / delete / export-import as JSON (lets users share a mapping for their bank).
- History drawer (v1): last 20 conversions from SQLite with Open / Show in folder.
- About: version, "Your files never leave this computer" line — this matters for a finance tool.

## 7. Feature backlog

**v0 (must ship)**
- Multi-PDF pick via native dialog, per-file status, password prompt.
- Bank-agnostic table extraction (header detection, column boundaries, continuation-line joining, noise filtering), layout fingerprinting and grouping.
- Column mapping screen with auto-suggestion, live validation (required fields, date format, reconciliation %), save as profile, auto-apply saved profiles.
- Workbook: Transactions + Summary, Indian format, real dates/numbers, freeze/autofilter/totals, Warnings sheet when needed.
- Filename + folder selection, overwrite handling, Open / Show in folder.
- Headless artisan command using the same pipeline (`--profile=<name>` or `--mapping=<json>` since there is no UI to map).
- Windows build + Azure Trusted Signing (or unsigned for internal use; SmartScreen will warn).

**v1**
- Drag-and-drop with native paths.
- Mapping screen extras: drag column dividers to fix boundaries, mark rows as header/noise, split a merged column by regex (e.g. `Ref` glued to `Narration`).
- Profile import/export as JSON so users can share a mapping. No bundled bank profiles: the app ships with zero bank knowledge; every profile is user-created.
- Reconciliation badge in Summary.
- Conversion history.
- Duplicate detection across overlapping statement periods (same date + amount + balance + narration).
- Category column via rules (UPI/NEFT/IMPS/RTGS/ATM/POS/EMI/Interest/Charges) and UPI payee/VPA extraction from narration into its own column.
- CSV export alongside XLSX.
- Auto-update via NativePHP updater (GitHub releases / S3).

**Later / maybe**
- Monthly pivot sheet (income vs expense by month) and per-category summary.
- Tally-ready sheet or Tally XML export (big win for CA/accountant users).
- Credit-card statements (different target schema: no running balance; needs a second `TargetField` set).
- OCR fallback (bundle Tesseract) for scanned PDFs; the mapping step is unchanged since it works on a table.
- Watch folder: auto-convert new PDFs dropped into a folder, using saved profiles only.
- Merge output into an existing workbook as a new sheet.
- Redaction option (mask account numbers in output).
- Hindi/regional UI strings.

## 8. Milestones

**M0 — Spike (½ day)**: `git init`; `composer require nativephp/desktop livewire/livewire`; `native:install`; run the 5 VERIFY items; drop `pdftotext.exe` into `extras/win`; hand-write a 2-cell XLSX and open it in Excel. Record outcomes in `.ai/rules`.

**M1 — Pipeline, headless (3–4 days)**: DTOs, `PopplerTextExtractor`, table extraction (`HeaderRowFinder`, `ColumnBoundaryDetector`, `RowSlicer`, `NoiseFilter`, `LayoutFingerprint`), `MappingSuggester`, `ApplyColumnMapping` (`IndianAmount`, `DateNormalizer`, `BalanceReconciler`), `XlsxWriter`, `StatementWorkbookBuilder`, `ConvertStatements` action, `statements:convert --mapping=` command. Pest tests on text fixtures covering at least 5 distinct synthetic layouts (separate Dr/Cr, single amount + marker, signed amount, two-line header, value-date column) and on the generated XLSX (unzip, assert XML).

**M2 — UI (3–4 days)**: `Converter` + `ColumnMapper` Livewire components with all states, stepper, dialogs, options, `MappingProfile` save/apply, Settings persistence, Open/Show in folder.

**M3 — Hardening (ongoing)**: run real statements from as many banks as available through the extractor, fix boundary/continuation/noise failures generically (never with bank-specific branches), grow the header synonym list, password-for-all, file-lock error path, unicode/space paths.

**M4 — Build & ship (1 day + signing setup)**: `npm run build && php artisan native:build win` on a Windows machine or GitHub Actions `windows-latest` (cross-compiling from macOS needs Wine + 32-bit NSIS; avoid). Signing via Azure Trusted Signing env vars. Test installer on a clean Windows VM: first launch, dialog paths, output to OneDrive-synced Documents folder, filename with unicode.

## 9. Testing

- Unit: `IndianAmount`, `DateNormalizer`, `BalanceReconciler`, `NumberFormats`, `LayoutFingerprint` — table-driven Pest datasets.
- Table extraction tests: `tests/Fixtures/layouts/<layout-name>/input.txt` (synthetic `-layout` dumps) → `expected-table.json` (columns + rows). Edge cases: multi-line narrations, page breaks mid-table, repeated headers on later pages, footer lines ("Page 2 of 5", "This is a computer generated statement"), two-line headers, right-aligned amounts overflowing into the neighbour column, opening-balance row without amounts, empty Debit or Credit cells.
- Mapping tests: `expected-mapping.json` per fixture asserts `MappingSuggester` output; `expected-transactions.json` asserts `ApplyColumnMapping` output for a given mapping, including warnings.
- `XlsxWriter`: write → `ZipArchive` open → assert `xl/worksheets/sheet1.xml` contains expected `<c r="E2" s="…"><v>123456.78</v></c>` and styles contain the Indian format code. Also a smoke test that LibreOffice (`soffice --headless --convert-to csv`) reads it, run only when `soffice` is present.
- Feature: `statements:convert --mapping=` end-to-end with a synthetic PDF generated in a test (or a tiny committed synthetic PDF) — skip when `pdftotext` is not available.
- Livewire: `Converter` and `ColumnMapper` state transitions with `PdfTextExtractor` faked via the container; `Dialog` facade faked (`Dialog::fake()` if provided, else bind a fake). Assert Next is disabled until required targets are mapped, swap suggestion appears when reconciliation < 90%, and a saved profile skips the mapping step.
- No real bank statements in the repo, ever. Add `*.pdf` under `tests/Fixtures/real/` to `.gitignore` for local manual checks.

## 10. Windows-specific constraints and gotchas

- Paths: spaces, unicode, OneDrive/Google Drive-synced folders, UNC. Always pass args as arrays to `Process`, never build a shell string.
- Output file locked while open in Excel → `ZipArchive::open` fails; detect and show the specific message.
- `pdftotext.exe` needs its sibling DLLs from the poppler-windows zip (`bin/` folder contents). Ship the whole `bin/` content, not just the exe.
- Poppler is GPL. Invoking it as a separate executable keeps the app's own code unaffected under the common interpretation, but ship the poppler licence text in About/`extras/win/LICENSE-poppler.txt`. If this is a concern, the pure-PHP fallback path exists (slower, more work for encryption).
- Unsigned builds trigger SmartScreen. Fine for personal/internal use; sign before distributing.
- Electron + PHP memory: cap `memory_limit` via `phpIni` in `NativeAppServiceProvider` if huge PDFs appear; stream `pdftotext` output to a temp file rather than stdout when > a few MB.
- Livewire temp uploads (if used for drag-drop) land in `storage/app/livewire-tmp` inside appdata; clean up after each conversion.

## 11. Open decisions for the owner

1. ~~Which banks first?~~ **Resolved: all banks.** No bank-specific parsers; bank-agnostic table extraction + user column mapping with saved, auto-applied profiles (section 4).
2. Bundle poppler (recommended) vs pure-PHP extraction (slower to build, weaker on encrypted files)?
3. Save As dialog only (simplest) vs filename field + folder picker (as specified above, slightly more UI)? Plan assumes both, with the field+picker as the primary.
4. Windows only, or also ship a macOS build since it comes almost free?
5. Signing: Azure Trusted Signing account available, or ship unsigned for now?
6. App name/id for `config/nativephp.php` (affects appdata folder and installer name).
