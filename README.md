# XL Statement

Convert **PDF bank statements → Excel**. Bank-agnostic table extraction with a short column-mapping step — not a pile of per-bank parsers.

**Flow:** Files → Map columns → Export

Dev: Laravel Herd (Mac). Ship target: **Windows NativePHP desktop**.

---

## Stack

| Area | Choice |
|------|--------|
| App | Laravel 13 · Livewire 4 · Blade · Tailwind v4 |
| PDF text | Poppler `pdftotext -layout` first → smalot (unlocked) → Papier (password) |
| Excel | Hand-rolled XLSX (`ZipArchive` + XML) |
| DB | SQLite |
| Desktop | [`nativephp/desktop`](https://nativephp.com) |

The map UI shows `Extracted with poppler|smalot|papier` so you can confirm which engine ran.

---

## Requirements

- PHP 8.4+, Composer, Node 20.19+ (or 22+)
- [Laravel Herd](https://herd.laravel.com) (or equivalent) for local web
- **Mac PDF quality:** [Poppler](https://poppler.freedesktop.org/) via Homebrew (`brew install poppler`)
- **Windows desktop build:** Git LFS (Poppler binaries under `extras/win/`), PHP 8.4, Node 22

---

## Local setup (Mac / Herd)

```bash
git clone https://github.com/harshB1709/xl-statement.git
cd xl-statement
git lfs install && git lfs pull   # Windows Poppler binaries
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install && npm run build
```

Point Herd at the project (or use `http://xl-statement.test`).

Optional — force Poppler when Herd’s PHP-FPM lacks Homebrew on `PATH`:

```env
PDFTOTEXT_PATH=/opt/homebrew/bin/pdftotext
```

```bash
composer run test
# or: php artisan test --compact
```

---

## Windows NativePHP build

Build the installer **on Windows** (Apple Silicon → Win via Wine often fails at rcedit/NSIS).

```powershell
git lfs install
git pull
git lfs pull

# Branding — must NOT be Laravel (that produces laravel.exe + NativePHP-looking shortcuts)
# .env:
#   APP_NAME="XL Statement"
#   NATIVEPHP_APP_DESCRIPTION="Convert PDF bank statements into Excel"
#   NATIVEPHP_APP_ID=com.xlstatement.app

composer install
php artisan xl:prepare-native-build   # fails loudly if icons/LFS/APP_NAME are wrong
Remove-Item -Recurse -Force nativephp\electron\dist -ErrorAction SilentlyContinue
php artisan native:build win
```

Installer: `nativephp\electron\dist\XL Statement-*-setup.exe` (name follows `APP_NAME`).
Install location is typically:

`%LOCALAPPDATA%\Programs\xl-statement\`

### After install — verify (paste these outputs)

```powershell
$dir = "$env:LOCALAPPDATA\Programs\xl-statement"
# If that folder is missing, list installs:
Get-ChildItem "$env:LOCALAPPDATA\Programs" | Select-Object Name

Get-ChildItem $dir -ErrorAction SilentlyContinue | Select-Object Name
Get-Item "$dir\*.exe" -ErrorAction SilentlyContinue | Select-Object Name, Length
Get-Item "$dir\extras\win\pdftotext.exe" -ErrorAction SilentlyContinue | Select-Object FullName, Length
# Length must be tens of KB+. Missing or ~130 bytes = Poppler not shipped / LFS stub.
```

Notes:
- **`where pdftotext` empty is normal** — Poppler is next to the exe under `extras\win`, not on PATH.
- Shortcut tooltip “A NativePHP electron application” means the Electron `package.json` was never patched (old build or `APP_NAME`/publish path bug). Rebuild after `xl:prepare-native-build`.
- Uninstall the old app, delete the Start Menu shortcut, then install the new setup (Windows caches icons).
- In-app convert should show **Extracted with poppler**.

---

## Project notes

- **Handoff / agent context:** [`STATUS.md`](STATUS.md) · original plan: [`PLAN.md`](PLAN.md)
- **Real PDF fixtures:** `tests/Fixtures/real/` (gitignored) — do not commit statements or passwords
- **Bundled Poppler (Windows):** `extras/win/` from [oschwartz10612/poppler-windows](https://github.com/oschwartz10612/poppler-windows) v26.07.0-0 — GPL; see `extras/win/LICENSE-poppler.txt` and `SOURCE.txt`
- **App icons:** `public/icon.png` / `.ico` / `.icns` (teal XL + grid). Synced into NativePHP build trees by `bin/sync-nativephp-icons.php`

---

## License

Application code: [MIT](LICENSE).

Bundled Poppler binaries are GPL-2.0; distributing the Windows desktop build inherits that obligation for those binaries.
