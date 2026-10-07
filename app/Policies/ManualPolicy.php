<?php

namespace App\Policies;

use App\Models\Manual;
use App\Models\User;

/**
 * Ver o descargar: admin siempre; operador y auditor según visible_para.
 * La administración (crear, editar, eliminar) va por el middleware role:admin.
 */
class ManualPolicy {
    public function view(User $usuario, Manual $manual): bool {
        return $manual->esVisiblePara($usuario);
    }
}
