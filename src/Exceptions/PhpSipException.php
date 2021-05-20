<?php

namespace EasybellLibs\Exceptions;

use Exception;

class PhpSipException extends Exception
{
    public function __construct($message, $code = 0)
    {
        parent::__construct($message, $code);
    }
}
