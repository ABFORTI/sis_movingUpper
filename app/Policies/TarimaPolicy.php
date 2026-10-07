<?php

namespace App\Policies;

use App\Models\Tarima;
use App\Models\User;

/**
 * Admin y auditor ven todas las tarimas; el operador solo las que creó.
 * Tarima::scopeVisiblesPara aplica la misma regla como consulta.
 */
class TarimaPolicy {
    public function view(User $usuario, Tarima $tarima): bool {
        return $usuario->puedeVerTodasLasTarimas()
            || ($tarima->created_by !== null && (int) $tarima->created_by === (int) $usuario->id);
    }

    public function guardar(User $usuario, Tarima $tarima): bool {
        return $this->view($usuario, $tarima);
    }

    /** Agregar o quitar contenido: solo mientras la tarima está en captura. */
    public function editar(User $usuario, Tarima $tarima): bool {
        return ! $tarima->estaGuardada() && $this->view($usuario, $tarima);
    }

    public function vaciar(User $usuario, Tarima $tarima): bool {
        return $this->editar($usuario, $tarima);
    }
}
