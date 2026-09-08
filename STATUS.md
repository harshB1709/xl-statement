# XL Statement — current status

Living handoff so you (or another agent) can switch tabs without losing context.  
**Spec / original plan:** [`PLAN.md`](PLAN.md)  
**App (Herd):** http://xl-statement.test  
**Git:** `main` @ `4c22118` (2 commits; no remote required yet)

Last updated: 2026-09-08 (Poppler win extras)

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
- Still VERIFY on a clean Win VM: NativePHP `extras` path + `Process` can run `extras/win/pdftotext.exe` (paths with spaces, UTF-8, `-upw`).
- `extras/mac/` still only `.gitkeep` (dev uses Homebrew / `PDFTOTEXT_PATH`).

---

## Table / mapping logic

- Fixed-width slice from header when layout is clean.
- **`DateLedRowAssembler`** when headers/columns are broken (common with smalot; also used after Poppler for some Kotak shapes).
- Hardening already done for: glued dates, Indian amounts, DR/CR as **balance nature** (CBI), Kotak serial prefixes (`1 09 Sep 2025…`), named-month dates, statement-period false starts.
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

1. VERIFY NativePHP extras path + `Process` with bundled `extras/win/pdftotext.exe` on a clean Win VM (spaces in path, UTF-8, `-upw`).
2. Real-PDF smoke suite for regression (optional CI skip when fixtures absent).
3. Locked CBI vs Poppler password compare (needs password only in local env — never commit).
4. Prefer stricter bank-agnostic shapes; strip growing bank-specific noise if it creeps in.
5. NativePHP build/sign/ship (PLAN M4).
6. `.ai/rules` still thin / missing — record durable decisions with Boost `record-rule` when useful.

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
