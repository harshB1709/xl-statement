<?php

return [
    /*
    | Pure PHP (smalot) is the default so Mac Herd and Windows NativePHP
    | share one extractor with no OS-specific binaries required.
    | Poppler is used only when available, mainly for password PDFs.
    */
    'pdftotext_path' => env('PDFTOTEXT_PATH'),
];
