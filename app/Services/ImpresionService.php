<?php

namespace App\Services;

use App\Models\Impresion;
use App\Models\NumeroSerie;
use App\Models\Tarima;
use App\Models\User;
use App\Services\Etiquetas\EtiquetaService;
use Illuminate\Support\Facades\DB;


class ImpresionService {
    public function __construct(
        private readonly TarimaService $tarimas,
        private readonly EtiquetaService $etiquetas,
    ) { }

    /**
     * @param  list<int>|null  $ids  piezas que se imprimieron
     * @return int 
     */
    public function registrar(Tarima $tarima, User $usuario, ?array $ids = null): int {
        $this->tarimas->asegurarImprimible($tarima);

        $pendientes = DB::transaction(function () use ($tarima, $usuario, $ids) {
            Tarima::whereKey($tarima->id)->lockForUpdate()->first();

            $piezas = $this->etiquetas->piezas($tarima, $ids);
            abort_if($piezas->isEmpty(), 422, "El {$tarima->nombre} no tiene piezas para etiquetar.");

            Impresion::create([
                'tarima_id' => $tarima->id,
                'user_id' => $usuario->id,
                'etiquetas' => $piezas->count(),
                'es_reimpresion' => $piezas->contains(fn (NumeroSerie $ns) => ! $ns->etiquetaPendiente()),
            ]);
            NumeroSerie::whereKey($piezas->modelKeys())->update(['etiqueta_impresa_at' => now()]);

            $pendientes = $this->etiquetas->pendientes($tarima)->count();
            if ($pendientes === 0) {
                Tarima::whereKey($tarima->id)->whereNull('guardada_at')->whereNull('impresa_at')->update(['impresa_at' => now()]);
                Tarima::whereKey($tarima->id)->update(['reimpresion_pendiente_at' => null]);
            }

            return $pendientes;
        });

        $tarima->refresh();

        return $pendientes;
    }
}
