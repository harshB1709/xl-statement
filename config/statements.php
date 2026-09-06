<?php

return [
    /*
    | Path to Poppler's pdftotext binary. When null, the app checks extras/,
    | then common Homebrew/system paths, then `which pdftotext`.
    | Herd's PHP-FPM often lacks Homebrew on PATH, so absolute paths matter.
    */
    'pdftotext_path' => env('PDFTOTEXT_PATH'),
];
