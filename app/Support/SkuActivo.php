<?php

namespace App\Support;

use App\Models\Registro;
use Illuminate\Contracts\Session\Session;

class SkuActivo {
    private const LLAVE = 'wms.sku_activo';

    public function __construct(private readonly Session $session) { }

    public function obtener(): ?Registro {
        $id = $this->session->get(self::LLAVE);
        if (! $id) {
            return null;
        }

        $registro = Registro::with(['tarima', 'destino'])->withCount('numerosSerie')->find($id);

        if (! $registro) {
            $this->cerrar();
        }

        return $registro;
    }

    public function activar(Registro $registro): void {
        $this->session->put(self::LLAVE, $registro->id);
    }

    public function cerrar(): void {
        $this->session->forget(self::LLAVE);
    }

    public function hay(): bool {
        return $this->session->has(self::LLAVE);
    }
}
