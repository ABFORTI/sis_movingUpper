<?php

namespace App\Services;

use App\Models\Impresion;
use App\Models\Tarima;
use App\Models\User;
use App\Services\Etiquetas\EtiquetaService;
use Illuminate\Support\Facades\DB;

/**
 * Registra cada impresión confirmada. Vive fuera de TarimaService porque
 * EtiquetaService ya depende de él (EtiquetaListado → TarimaService).
 */
class ImpresionService {
    public function __construct(
        private readonly TarimaService $tarimas,
        private readonly EtiquetaService $etiquetas,
    ) { }

    public function registrar(Tarima $tarima, User $usuario): void {
        $this->tarimas->marcarImpresa($tarima);

        DB::transaction(function () use ($tarima, $usuario) {
            // El FOR UPDATE serializa dos confirmaciones simultáneas (doble clic)
            // para que solo la primera quede como impresión original.
            Tarima::whereKey($tarima->id)->lockForUpdate()->first();

            Impresion::create([
                'tarima_id' => $tarima->id,
                'user_id' => $usuario->id,
                'etiquetas' => count($this->etiquetas->paginas($tarima)),
                'es_reimpresion' => Impresion::where('tarima_id', $tarima->id)->exists(),
            ]);
        });
    }
}
