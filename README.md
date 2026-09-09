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
git lfs pull                    # REQUIRED — without this, extras/win/*.exe are ~130-byte stubs
# Confirm Poppler is real (~70KB+), not an LFS pointer:
#   Get-Item extras\win\pdftotext.exe | Select-Object Length

# Product name / exe / shortcut icon branding:
#   In .env set APP_NAME="XL Statement"  (not Laravel)

composer install
composer dump-autoload          # pdfPageSize patch + icon sync + InstallsAppIcon patch
npm install

# Wipe previous Electron output so icons are not reused from cache:
Remove-Item -Recurse -Force nativephp\electron\dist -ErrorAction SilentlyContinue

# PHP 8.4 on PATH (not XAMPP 7.x), Node 22 via nvm-windows
php artisan native:build win
```

Before shipping, from the **project** (not PATH):

```powershell
php artisan xl:diagnose-poppler
```

Installer / unpacked app: `nativephp/electron/dist/`.

### After install — verify

1. **`where pdftotext` can be empty.** That is normal. Poppler is bundled next to the app exe, not on PATH.
2. In File Explorer open the install / `win-unpacked` folder and confirm:
   - `extras\win\pdftotext.exe` exists and is **tens of KB+** (not ~130 bytes)
   - Shortcut / exe is **not** named `laravel.exe` (fix `APP_NAME` and rebuild)
3. Convert a statement — UI should say **Extracted with poppler**.
4. If the Start Menu still shows the NativePHP “N”, uninstall, delete the old shortcut, reinstall (Windows caches icons aggressively).

If still smalot: run `php artisan xl:diagnose-poppler` on the Windows **dev** tree, and check [`STATUS.md`](STATUS.md).

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
