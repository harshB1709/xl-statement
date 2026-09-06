<?php

namespace App\Exceptions;

use Exception;

class PdfHasNoTextLayer extends Exception
{
    public function __construct(public string $path)
    {
        parent::__construct('This PDF appears to be scanned and has no extractable text.');
    }
}
