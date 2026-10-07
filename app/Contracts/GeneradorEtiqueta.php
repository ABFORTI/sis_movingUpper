<?php

namespace App\Contracts;

use App\Models\Tarima;
use App\Services\Etiquetas\Lienzo;

interface GeneradorEtiqueta {
    public function aplicaA(Tarima $tarima): bool;

    /** @return list<Lienzo> una etiqueta física por elemento. */
    public function paginas(Tarima $tarima): array;
}
