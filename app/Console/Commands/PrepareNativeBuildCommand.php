<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class PrepareNativeBuildCommand extends Command
{
    protected $signature = 'xl:prepare-native-build
                            {--skip-lfs-check : Do not verify extras/win Poppler size}';

    protected $description = 'Validate branding + Poppler extras, sync icons, and patch NativePHP before native:build';

    public function handle(): int
    {
        $failed = false;

        $appName = (string) config('app.name');
        $this->line('APP_NAME / config(app.name): '.$appName);

        if ($appName === '' || strcasecmp($appName, 'Laravel') === 0) {
            $this->error('Set APP_NAME="XL Statement" in .env (currently Laravel/empty). NSIS will otherwise ship laravel.exe + wrong branding.');
            $failed = true;
        }

        $description = (string) config('nativephp.description');
        $this->line('nativephp.description: '.$description);

        if ($description === '' || str_contains(strtolower($description), 'nativephp')) {
            $this->error('Set NATIVEPHP_APP_DESCRIPTION in .env / config — shortcut tooltip still looks like NativePHP.');
            $failed = true;
        }

        foreach (['icon.png', 'icon.ico', 'icon.icns'] as $icon) {
            $path = public_path($icon);

            if (! is_file($path)) {
                $this->error('Missing public/'.$icon);
                $failed = true;
            }
        }

        if (! $this->option('skip-lfs-check')) {
            $pdftotext = base_path('extras/win/pdftotext.exe');

            if (! is_file($pdftotext)) {
                $this->error('Missing extras/win/pdftotext.exe — run: git lfs pull');
                $failed = true;
            } else {
                $size = filesize($pdftotext) ?: 0;
                $this->line('extras/win/pdftotext.exe size: '.$size.' bytes');

                if ($size < 1024) {
                    $this->error('pdftotext.exe looks like a Git LFS pointer. Run: git lfs install && git lfs pull');
                    $failed = true;
                }
            }
        }

        $this->newLine();
        $this->info('Running icon sync + NativePHP trait patches…');
        $result = Process::path(base_path())->run([PHP_BINARY, 'bin/sync-nativephp-icons.php']);
        $this->output->write($result->output().$result->errorOutput());

        if ($result->failed()) {
            $failed = true;
        }

        $electronIcon = base_path('vendor/nativephp/desktop/resources/electron/build/icon.png');

        if (is_file($electronIcon)) {
            $iconSize = filesize($electronIcon) ?: 0;
            $this->line('electron build/icon.png size: '.$iconSize.' bytes (custom XL ≈16KB; NativePHP default ≈400KB)');

            if ($iconSize > 100_000) {
                $this->error('Electron build icon still looks like the default NativePHP mark.');
                $failed = true;
            }
        }

        if ($failed) {
            $this->newLine();
            $this->error('Fix the issues above, then: Remove-Item -Recurse nativephp\\electron\\dist ; php artisan native:build win');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Ready. Next: delete nativephp/electron/dist, then php artisan native:build win');
        $this->warn('After install, check %LOCALAPPDATA%\\Programs\\xl-statement\\extras\\win\\pdftotext.exe (folder name follows APP_NAME slug).');

        return self::SUCCESS;
    }
}
