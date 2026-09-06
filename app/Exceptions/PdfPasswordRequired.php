<?php

namespace App\Exceptions;

use Exception;

class PdfPasswordRequired extends Exception
{
    public function __construct(public string $path)
    {
        parent::__construct('This PDF is password-protected. Enter the password to unlock it.');
    }
}
