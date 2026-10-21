<?php

namespace App\Exceptions;

use RuntimeException;

/** Fallo de la lectura por foto con un mensaje apto para el operador y su código HTTP. */
class OcrException extends RuntimeException {
    public function __construct(string $mensaje, public readonly int $status = 502) {
        parent::__construct($mensaje);
    }
}
