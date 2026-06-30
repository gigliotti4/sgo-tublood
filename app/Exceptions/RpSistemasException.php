<?php

namespace App\Exceptions;

use RuntimeException;

class RpSistemasException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $servicio = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
