<?php

/**
 * NativePHP desktop 2.3.0 ships without compiled pdfPageSize.js (upstream #152).
 * Restore it from patches/ until a fixed release is installed.
 */
$destination = __DIR__.'/../vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/pdfPageSize.js';
$patch = __DIR__.'/../patches/nativephp-pdfPageSize.js';

if (! is_file($patch)) {
    return;
}

if (is_file($destination) && filesize($destination) > 0) {
    return;
}

@mkdir(dirname($destination), 0777, true);

if (! copy($patch, $destination)) {
    fwrite(STDERR, "Failed to restore NativePHP pdfPageSize.js\n");
    exit(1);
}

echo "Restored missing NativePHP pdfPageSize.js (upstream #152).\n";
