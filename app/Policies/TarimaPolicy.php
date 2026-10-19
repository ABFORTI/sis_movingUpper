<?php

namespace App\Policies;

use App\Models\Tarima;
use App\Models\User;

/**
 * Todos los usuarios trabajan la misma mudanza, así que todos ven todas las
 * tarimas (en lectura). Operarlas es del que las creó; admin y auditor operan
 * cualquiera. Tarima::scopeTrabajablesPor aplica la misma regla como consulta.
 */
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

    /** Agregar o quitar contenido: solo mientras la tarima está en captura. */
    public function editar(User $usuario, Tarima $tarima): bool {
        return ! $tarima->estaGuardada() && ! $tarima->estaAnulada() && $this->trabajar($usuario, $tarima);
    }

    public function vaciar(User $usuario, Tarima $tarima): bool {
        return $this->editar($usuario, $tarima);
    }

    /** Mover o retirar piezas de una tarima ya guardada (PartidaService). */
    public function corregir(User $usuario, Tarima $tarima): bool {
        return $usuario->puedeCorregirTarimas() && $tarima->estaGuardada() && ! $tarima->estaAnulada();
    }

    /**
     * Anular sustituye al borrado: la tarima conserva número y contenido. El admin
     * anula cualquiera (con motivo); los demás solo una que puedan trabajar, en
     * captura y vacía, típicamente creada por error.
     */
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
