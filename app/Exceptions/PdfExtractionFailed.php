<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class PdfExtractionFailed extends Exception
{
    public function __construct(string $message, public string $path, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
