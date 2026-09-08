<?php

return [
    /*
    | Path to Poppler's pdftotext binary. When null, the app checks:
    | NATIVEPHP_EXTRAS_PATH (packaged desktop), Storage disk extras, extras/,
    | common Homebrew/system paths, then which/where. Herd's PHP-FPM often
    | lacks Homebrew on PATH; packaged NativePHP puts extras next to the exe.
    */
    'pdftotext_path' => env('PDFTOTEXT_PATH'),
];
