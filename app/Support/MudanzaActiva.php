<?php

namespace App\Support;

use App\Models\Mudanza;

/**
 * La mudanza en curso (activa, suspendida o en cierre). Es el único punto donde
 * se decide "en qué mudanza se trabaja": el día que haya varias a la vez, aquí
 * se cambia por la mudanza que el usuario eligió.
 */
class MudanzaActiva {
    private ?Mudanza $mudanza = null;
    private bool $cargada = false;

    public function obtener(): ?Mudanza {
        if (! $this->cargada) {
            $this->mudanza = Mudanza::enCurso()->first();
            $this->cargada = true;
        }

        return $this->mudanza;
    }

    /** La en curso o, si no hay, la más reciente: la que se muestra por defecto en el historial. */
    public function predeterminada(): ?Mudanza {
        return $this->obtener() ?? Mudanza::whereNot('estado', Mudanza::BORRADOR)->latest('id')->first();
    }

    /** La mudanza pedida en un filtro (historial, Excel) o, si no se pidió, la predeterminada. */
    public function elegida(?int $id): ?Mudanza {
        return $id ? Mudanza::find($id) : $this->predeterminada();
    }
}
