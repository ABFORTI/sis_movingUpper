<?php

namespace App\Exceptions;

use RuntimeException;

class OcrException extends RuntimeException {
    public function __construct(string $mensaje, public readonly int $status = 502) {
        parent::__construct($mensaje);
    }
}
