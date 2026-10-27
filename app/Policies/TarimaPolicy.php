<?php

namespace App\Policies;

use App\Models\Tarima;
use App\Models\User;


class TarimaPolicy {
    public function view(User $usuario, Tarima $tarima): bool {
        return true;
    }

    public function trabajar(User $usuario, Tarima $tarima): bool {
        return $usuario->puedeTrabajarTodasLasTarimas()
            || ($tarima->created_by !== null && (int) $tarima->created_by === (int) $usuario->id);
    }

    public function guardar(User $usuario, Tarima $tarima): bool {
        return ! $tarima->estaAnulada() && $this->trabajar($usuario, $tarima);
    }


    public function editar(User $usuario, Tarima $tarima): bool {
        return ! $tarima->estaGuardada() && ! $tarima->estaAnulada() && $this->trabajar($usuario, $tarima);
    }

    public function vaciar(User $usuario, Tarima $tarima): bool {
        return $this->editar($usuario, $tarima);
    }


    public function corregir(User $usuario, Tarima $tarima): bool {
        return $usuario->puedeCorregirTarimas() && $tarima->estaGuardada() && ! $tarima->estaAnulada();
    }


    public function anular(User $usuario, Tarima $tarima): bool {
        if ($tarima->estaAnulada()) {
            return false;
        }

        if ($usuario->isAdmin()) {
            return true;
        }

        return $this->editar($usuario, $tarima) && ! $tarima->registros()->exists();
    }
}
