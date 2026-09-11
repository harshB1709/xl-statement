# XL Statement — current status

Living handoff so you (or another agent) can switch tabs without losing context.  
**Spec / original plan:** [`PLAN.md`](PLAN.md)  
**App (Herd):** http://xl-statement.test  
**Git:** `main` → https://github.com/harshB1709/xl-statement.git

Last updated: 2026-09-11 (skip Export step; fix NativePHP detection)


---

## What it is

Laravel 13 + Livewire 4 app that converts **PDF bank statements → Excel**. Bank-agnostic table extraction + user column mapping (not per-bank parsers). Dev on Mac Herd now; **Windows NativePHP desktop** later.

Flow: **Files → Map columns → Export**

---

## Stack (settled)

| Area | Choice |
|------|--------|
| PDF text | **Poppler `pdftotext -layout` first** when binary found; fallback **smalot** (unlocked) / **Papier** (password) |
| Excel | Hand-rolled XLSX (`ZipArchive` + XML) — NativePHP PHP lacks xmlreader/xmlwriter |
| UI | Livewire 4 + Blade + Tailwind v4; themes Ledger Mist / Graphite Reef |
| DB | SQLite |
| Desktop | `nativephp/desktop` (ship later) |

---

## Extraction pipeline (important)

`PdfTextExtractor` → `PreferPhpPdfTextExtractor`:

1. If Poppler available → use it (unlocked **and** password via `-upw`)
2. Else smalot; password / empty-text edge cases → Papier; last resort Poppler again if present

**Map UI shows** `Extracted with poppler|smalot|papier` so you can verify the engine.

### Poppler on Mac / Herd (solved)

Herd PHP-FPM often **lacks Homebrew on `PATH`**, so `which pdftotext` failed and the app **silently used smalot** (looked like “Poppler not working” even after hard reload / incognito).

**Fix (in `4c22118`):** resolve absolute candidates (`/opt/homebrew/bin/pdftotext`, extras/, then `which`).  
**Local `.env`:** `PDFTOTEXT_PATH=/opt/homebrew/bin/pdftotext`  
Brew: `brew install poppler` → binary at `/opt/homebrew/bin/pdftotext`

### Windows shipping (binaries dropped; VERIFY pending)

- `extras/win/` now has **`pdftotext.exe` + PE import-closure DLLs** from [oschwartz10612/poppler-windows](https://github.com/oschwartz10612/poppler-windows) **v26.07.0-0** (~55MB). Other Poppler CLIs omitted. See `extras/win/SOURCE.txt`.
- **Git LFS:** `extras/win/*.exe` and `extras/win/*.dll` (requires `git lfs install`). License/SOURCE text files are normal git.
- GPL texts: `LICENSE-poppler.txt` (+ poppler-data / Adobe COPYING files).
- `extras/mac/` still only `.gitkeep` (dev uses Homebrew / `PDFTOTEXT_PATH`).

#### NativePHP 2.3.0 build notes (2026-09-08)

- **`pdfPageSize.js` missing** in `nativephp/desktop` 2.3.0 → electron-vite fails. Upstream: [#152](https://github.com/NativePHP/desktop/issues/152) / [#153](https://github.com/NativePHP/desktop/pull/153). Workaround: `patches/nativephp-pdfPageSize.js` restored by Composer `post-autoload-dump` when absent.
- **“renderer and preload config is missing”** is a harmless electron-vite warning (Laravel UI is not a Vite renderer).
- Mac → `native:build win`: electron-vite succeeds; `win-unpacked/` packs **`extras/win/pdftotext.exe`**. Final NSIS/icon step fails on Apple Silicon with electron-builder’s Intel Wine (`bad CPU type`). Prefer building the installer on Windows, or install ARM Wine later.
- Still VERIFY on a clean Win machine: open `win-unpacked` / installer and run convert (spaces, UTF-8, `-upw`).

---

## Table / mapping logic

- Fixed-width slice from header when layout is clean.
- **`DateLedRowAssembler`** when headers/columns are broken (common with smalot; also used after Poppler for some Kotak shapes).
- Hardening already done for: glued dates, Indian amounts, DR/CR as **balance nature** (CBI), Kotak serial prefixes (`1 09 Sep 2025…`), named-month dates, statement-period false starts.
- **CBI + Poppler “hallucination”:** two-line headers (`Value`/`Date`, `Branch`/`Code`, `Cheque`/`Number`) + bad fixed-width cuts. Merge continuation header lines; if slices still look chopped (`/04/2025`, `,23,500.00`), force `DateLedRowAssembler`.
- Dates: format fallbacks + Carbon loose parse (`07 Sep 2025`, etc.).
- Export: optional Excel-only Indian lakhs format (default off — Numbers.app breaks on `\,`).
- Unlock UX: wrong password message; password passed through to Convert.

---

## Real PDF fixtures (gitignored)

Under `tests/Fixtures/real/` (only `README.md` + `.gitignore` are committed):

| File | Notes |
|------|--------|
| `axis.pdf` | smalot ≈ Poppler |
| `idfc.pdf` | smalot ≈ Poppler |
| `kotak.pdf` | Long savings; ~634 rows / ~100% reconcile; Poppler much less glue than smalot |
| `kotak-2.pdf` | Short CURRENT (2 txns). **Poppler clearly wins** — clean 2 rows. Smalot → 4 mashed rows / `#TRANSACTION…` headers |
| `locked-cbi.pdf` | Password-protected CBI (was `locked-hdfc.pdf`) |

**Do not commit** real statements or passwords.

---

## Key paths

```
app/Services/Pdf/PreferPhpPdfTextExtractor.php
app/Services/Pdf/PopplerTextExtractor.php      # isAvailable(), candidate paths
app/Services/Pdf/SmalotPdfTextExtractor.php
app/Services/Pdf/PapierPdfTextExtractor.php
app/Actions/ExtractStatementTable.php
app/Services/Table/DateLedRowAssembler.php
app/Livewire/Converter.php
resources/views/livewire/converter.blade.php
config/statements.php                          # PDFTOTEXT_PATH
extras/win/                                    # pdftotext.exe + DLLs (v26.07.0-0)
```

Artisan spike (older): `php artisan statements:spike-papier` — compare Papier vs Smalot.

---

## What’s working now

- End-to-end convert on Herd with **Poppler-first** (after path fix).
- `kotak-2` fresh upload → engine **poppler**, **2 rows**, headers `Date / Description / Debit / Credit / Balance`.
- PreferPhp + Poppler path tests green.
- Initial git history exists.
- **PrinsFrank spike (2026-09-08): rejected** — see Libraries considered.

---

## Still open / next

1. Finish Windows package on a **Windows** box (or ARM Wine): `php artisan native:build win`, then VERIFY convert with bundled `extras/win/pdftotext.exe` (spaces, UTF-8, `-upw`). Mac can produce `win-unpacked` past vite, but installer/rcedit needs working Wine/Windows.
2. Drop `patches/nativephp-pdfPageSize.js` once `nativephp/desktop` ships the #153 fix.
3. Real-PDF smoke suite for regression (optional CI skip when fixtures absent).
4. Locked CBI vs Poppler password compare (needs password only in local env — never commit).
5. Prefer stricter bank-agnostic shapes; strip growing bank-specific noise if it creeps in.
6. NativePHP build/sign/ship (PLAN M4).
7. `.ai/rules` still thin / missing — record durable decisions with Boost `record-rule` when useful.

---

## Libraries considered (not adopted)

| Option | Verdict |
|--------|---------|
| Rewrite `pdftotext` in PHP | Not practical |
| `prinsfrank/pdfparser` v3.3.0 | **Spiked 2026-09-08 — do not adopt.** Axis hard-fail (`ColorSpace`); long Kotak 108/634 rows @ 8.4% reconcile; AES-256 unlock fails (`/AESV3`). Beats smalot on `kotak-2` only. Keep Poppler → smalot → Papier. |
| SetaPDF-Extractor | Strong, commercial + redistribution license for desktop |
| LiteParse / FirePDF / Kreuzberg FFI | Layout-aware, heavier NativePHP packaging |
| `spatie/pdf-to-text` | Thin Poppler wrapper only — no quality gain |

Poppler is **GPL-2.0**; open source. Ship binary + license; only invoke `pdftotext`.

---

## Quick commands

```bash
# PreferPhp / Poppler tests
php artisan test --compact tests/Feature/PreferPhpPdfTextExtractorTest.php tests/Feature/PopplerTextExtractorTest.php

# Full suite (ask user after feature work)
php artisan test --compact

# Confirm Poppler from app context
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $p=app(App\Services\Pdf\PopplerTextExtractor::class); echo $p->resolveBinary(),"\n",$p->isAvailable()?"yes":"no","\n";'
```

---

## Agent notes

- Before editing: read matching `.ai/rules` if present; use project skills (laravel-best-practices, testing-best-practices, livewire, tailwind).
- Prefer Boost MCP tools when available (`search-docs`, `database-schema`, `get-absolute-url`, `record-rule`).
- Never commit `tests/Fixtures/real/*.pdf` or secrets.
- If UI shows `#TRANSACTION` / 4 junk rows on kotak-2 → **smalot**, not Poppler — check engine label and binary resolution.
- **`where pdftotext` empty is OK** on Windows. Packaged Poppler is `extras/win` next to the exe (`NATIVEPHP_EXTRAS_PATH`), never PATH. Diagnose: `php artisan xl:diagnose-poppler`.
- **Packaged Windows:** also walk ancestors of `base_path` / `PHP_BINARY` for `extras/…`; reject Git LFS pointer stubs (&lt;1KB / `version https://git-lfs…`). Always `git lfs pull` before `native:build`.
- App mark: `resources/images/logo.svg` + `public/favicon.svg`; NativePHP OS icons: `public/icon.png` / `.ico` / `.icns` (teal XL + grid). Workspace: `tmp/custom-icons/xl-statement-mark/`.
- **NSIS installer branding:** shortcut tooltip “A NativePHP electron application” + default “N” icon means Electron `package.json` / `build/icon.*` were not updated. Causes: `.env` `APP_NAME=Laravel`, and/or upstream `electronPath('package.json'|'build/icon.png')` bug with a published Electron project. Mitigated by patches in `bin/sync-nativephp-icons.php` + `php artisan xl:prepare-native-build`. Install dir is usually `%LOCALAPPDATA%\Programs\xl-statement\` — check `extras\win\pdftotext.exe` there (not `where`).
