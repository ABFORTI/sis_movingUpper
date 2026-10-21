<?php

namespace App\Policies;

use App\Models\Manual;
use App\Models\User;


class ManualPolicy {
    public function view(User $usuario, Manual $manual): bool {
        return $manual->esVisiblePara($usuario);
    }
}
