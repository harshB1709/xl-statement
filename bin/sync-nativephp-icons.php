<?php

/**
 * Keep NativePHP desktop builds on XL Statement branding.
 *
 * 1. Copy public/icon.* into every Electron buildResources location.
 * 2. Patch InstallsAppIcon + PatchesPackagesJson so electronPath('…/file')
 *    cannot miss a published nativephp/electron project (upstream path bug).
 */
$root = dirname(__DIR__);
$sources = [
    'icon.png' => $root.'/public/icon.png',
    'icon.ico' => $root.'/public/icon.ico',
    'icon.icns' => $root.'/public/icon.icns',
];

foreach ($sources as $file) {
    if (! is_file($file)) {
        fwrite(STDERR, "Missing app icon source: {$file}\n");
        exit(1);
    }
}

$targets = [
    $root.'/vendor/nativephp/desktop/resources/electron/build',
    $root.'/vendor/nativephp/desktop/resources/build',
];

if (is_file($root.'/nativephp/electron/package.json')) {
    $targets[] = $root.'/nativephp/electron/build';
}

$copied = 0;

foreach ($targets as $directory) {
    if (! is_dir(dirname($directory))) {
        continue;
    }

    if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fwrite(STDERR, "Failed to create icon directory: {$directory}\n");
        exit(1);
    }

    foreach ($sources as $name => $source) {
        $destination = $directory.'/'.$name;

        if (! copy($source, $destination)) {
            fwrite(STDERR, "Failed to copy {$name} to {$destination}\n");
            exit(1);
        }

        $copied++;
    }
}

$patches = [
    $root.'/patches/nativephp-InstallsAppIcon.php' => $root.'/vendor/nativephp/desktop/src/Drivers/Electron/Traits/InstallsAppIcon.php',
    $root.'/patches/nativephp-PatchesPackagesJson.php' => $root.'/vendor/nativephp/desktop/src/Drivers/Electron/Traits/PatchesPackagesJson.php',
];

foreach ($patches as $patch => $destination) {
    if (! is_file($patch) || ! is_dir(dirname($destination))) {
        continue;
    }

    if (! copy($patch, $destination)) {
        fwrite(STDERR, 'Failed to patch '.basename($destination)."\n");
        exit(1);
    }

    echo 'Patched NativePHP '.basename($destination)."\n";
}

if ($copied > 0) {
    echo "Synced NativePHP app icons ({$copied} files).\n";
}
