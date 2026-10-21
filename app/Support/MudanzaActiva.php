<?php

namespace App\Support;

use App\Models\Mudanza;


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


    public function predeterminada(): ?Mudanza {
        return $this->obtener() ?? Mudanza::whereNot('estado', Mudanza::BORRADOR)->latest('id')->first();
    }


    public function elegida(?int $id): ?Mudanza {
        return $id ? Mudanza::find($id) : $this->predeterminada();
    }
}
