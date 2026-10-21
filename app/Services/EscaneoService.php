<?php

namespace App\Services;

use App\Enums\TipoRegistro;
use App\Models\InventarioCarga;
use App\Models\InventarioItem;
use App\Models\NumeroSerie;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use App\Services\Inventario\InventarioService;
use App\Support\ConversionSku;
use App\Support\NumeroSerieNormalizado;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Captura en la tarima: SKU (siempre del inventario del almacén de origen de la
 * tarima) + N/S. Toda N/S se busca en los tres números de serie de ese SKU en
 * ese inventario; si no coincide, solo se registra si el operador acepta
 * marcarla como incidencia de "no encontrado en inventario".
 */
class EscaneoService {
    public const REGISTRADA = 'registrada';
    public const REPETIDA = 'repetida';

    public function __construct(
        private readonly TarimaService $tarimas,
        private readonly BitacoraService $bitacora,
        private readonly InventarioService $inventario,
        private readonly IncidenciaService $incidencias,
    ) { }

    /**
     * Abre (o reabre) el SKU en la tarima. El SKU debe existir en el inventario
     * vigente del almacén de origen de la tarima; la descripción se toma del
     * inventario, no de lo que mande el navegador.
     */
    public function registrarSkuPadre(Tarima $tarima, string $sku, User $usuario): Registro {
        $producto = $this->productoDe($this->inventarioDe($tarima, 'sku'), $sku);
        $sku = $producto['sku'];

        try {
            return DB::transaction(function () use ($tarima, $producto, $usuario) {
                $this->asegurarTemporal($tarima, 'sku');

                // Un SKU que ya se cerró en esta tarima se vuelve a abrir para
                // seguir escaneando sus N/S: cerrado no significa terminado.
                // Activar el SKU no agrega piezas: no hay etiqueta nueva que imprimir.
                return $this->modeloEn($tarima, $producto, $usuario);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => "El SKU {$sku} ya está en el {$tarima->nombre}."]);
        }
    }

    /**
     * Agrega una N/S (escaneada o escrita a mano: mismas reglas) al SKU activo.
     *
     * - Coincide con un renglón del SKU en el inventario del almacén → se
     *   registra ligada a ese renglón.
     * - No coincide → se registra igual y se abre automáticamente su incidencia
     *   de "No encontrado en inventario" (Abierta), en la misma transacción. No
     *   se pregunta nada al operador: el escaneo sigue.
     * - Ya estaba en este mismo SKU (reintento, doble lectura) → REPETIDA, sin
     *   cambios ni otra incidencia.
     *
     * @return array{estado: string, serie: ?NumeroSerie, coincidencia: ?string, incidencia: ?\App\Models\Incidencia, pista: ?string, numero_serie: string}
     */
    public function agregarNumeroSerie(Registro $padre, string $capturada, User $usuario): array {
        $capturada = NumeroSerieNormalizado::limpio($capturada);
        if ($capturada === '') {
            throw ValidationException::withMessages(['numero_serie' => 'Escanea o escribe una N/S.']);
        }

        $tarima = $padre->tarima;
        $carga = $this->inventarioDe($tarima, 'numero_serie');

        try {
            return DB::transaction(function () use ($padre, $tarima, $carga, $capturada, $usuario) {
                $this->asegurarTemporal($tarima, 'numero_serie');

                [$valor, $item, $existente] = $this->validarSerie($carga, $padre->sku, $capturada);
                if ($existente?->registro_id === $padre->id) {
                    return $this->resultado(self::REPETIDA, $existente->numero_serie, serie: $existente);
                }
                if ($existente) {
                    throw ValidationException::withMessages(['numero_serie' => $this->yaRegistrada($existente)]);
                }

                if ($item) {
                    $serie = $this->crearSerie($padre, $valor, $usuario, $item);

                    return $this->resultado(self::REGISTRADA, $valor, serie: $serie, coincidencia: $item->campo);
                }

                // Sin coincidencia: la pieza se registra y su incidencia se abre sola.
                $pista = $this->inventario->pista($carga, $padre->sku, $capturada);
                $serie = $this->crearSerie($padre, $valor, $usuario);
                $incidencia = $this->incidencias->crearNoEncontrada($serie, $tarima, $usuario, $pista);

                return $this->resultado(self::REGISTRADA, $valor, serie: $serie, incidencia: $incidencia, pista: $pista);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'numero_serie' => "La N/S {$capturada} (o ese producto del inventario) se acaba de registrar en otra captura.",
            ]);
        }
    }

    /**
     * Agrega una N/S a un modelo (SKU) del embarque desde "Editar números de
     * serie", sin activarlo: mismas validaciones que el escaneo. Solo mientras
     * el embarque no tenga etiquetas impresas.
     *
     * @return array{estado: string, serie: ?NumeroSerie, coincidencia: ?string, incidencia: ?\App\Models\Incidencia, pista: ?string, numero_serie: string}
     */
    public function agregarAlModelo(Tarima $tarima, Registro $registro, string $capturada, User $usuario): array {
        if ($registro->tarima_id !== $tarima->id || $registro->tipo !== TipoRegistro::Padre) {
            throw ValidationException::withMessages(['numero_serie' => "Ese modelo no es del {$tarima->nombre}. Recarga la página."]);
        }
        $this->asegurarSinEtiquetas($tarima);

        $resultado = $this->agregarNumeroSerie($registro->setRelation('tarima', $tarima), $capturada, $usuario);
        if ($resultado['estado'] === self::REGISTRADA) {
            $this->bitacora->registrar('numero_serie.agregada', $usuario, $tarima, [
                'tarima' => $tarima->codigo, 'sku' => $registro->sku, 'numero_serie' => $resultado['numero_serie'],
            ], mudanza: $tarima->mudanza);
        }

        return $resultado;
    }

    /**
     * Corrige una N/S de un embarque en captura: su número de serie, su SKU o
     * ambos. Se valida igual que una lectura nueva (inventario del almacén de
     * origen, sin duplicados); si cambia el SKU, la pieza pasa a ese modelo del
     * mismo embarque (se crea si no estaba). Si su incidencia de "no encontrado"
     * ya no aplica se resuelve, y si el valor nuevo no coincide se abre otra.
     * Solo mientras el embarque no tenga etiquetas impresas.
     *
     * @return array{serie: NumeroSerie, antes: array{sku: string, numero_serie: string}, incidencia: ?\App\Models\Incidencia}
     */
    public function editarNumeroSerie(Tarima $tarima, NumeroSerie $serie, string $sku, string $capturada, User $usuario): array {
        $capturada = NumeroSerieNormalizado::limpio($capturada);
        if ($capturada === '') {
            throw ValidationException::withMessages(['numero_serie' => 'Escribe la N/S.']);
        }
        $this->asegurarSinEtiquetas($tarima);
        $carga = $this->inventarioDe($tarima, 'sku');
        $producto = $this->productoDe($carga, $sku);

        try {
            return DB::transaction(function () use ($tarima, $serie, $producto, $carga, $capturada, $usuario) {
                $this->asegurarTemporal($tarima, 'numero_serie');
                $actual = NumeroSerie::with(['registro', 'incidenciaPendiente'])
                    ->whereHas('registro', fn ($q) => $q->where('tarima_id', $tarima->id))
                    ->whereKey($serie->id)->lockForUpdate()->first()
                    ?? throw ValidationException::withMessages(['numero_serie' => "Esa N/S ya no está en el {$tarima->nombre}. Recarga la página."]);
                $antes = ['sku' => $actual->registro->sku, 'numero_serie' => $actual->numero_serie];

                [$valor, $item, $existente] = $this->validarSerie($carga, $producto['sku'], $capturada, $actual);
                if ($existente) {
                    throw ValidationException::withMessages(['numero_serie' => $this->yaRegistrada($existente)]);
                }
                if ($antes === ['sku' => $producto['sku'], 'numero_serie' => $valor]) {
                    throw ValidationException::withMessages(['numero_serie' => 'No hay cambios: la N/S y el SKU son los mismos.']);
                }

                $registro = $this->modeloEn($tarima, $producto, $usuario);
                $actual->update([
                    'registro_id' => $registro->id,
                    'numero_serie' => $valor,
                    'inventario_item_id' => $item?->id,
                    'coincidencia' => $item?->campo,
                    'etiqueta_impresa_at' => null,
                ]);
                $this->tarimas->invalidarImpresion($tarima);

                if ($actual->incidenciaPendiente) {
                    $this->incidencias->resolver($actual->incidenciaPendiente, 'corregido_upper',
                        "Se corrigió la N/S {$antes['numero_serie']} (SKU {$antes['sku']}) a {$valor} (SKU {$producto['sku']}) en el {$tarima->nombre}.", $usuario);
                }
                $incidencia = $item ? null : $this->incidencias->crearNoEncontrada(
                    $actual->setRelation('registro', $registro), $tarima, $usuario, $this->inventario->pista($carga, $producto['sku'], $capturada),
                );

                $this->bitacora->registrar('numero_serie.editada', $usuario, $tarima, [
                    'tarima' => $tarima->codigo, 'antes' => $antes, 'despues' => ['sku' => $producto['sku'], 'numero_serie' => $valor],
                ], mudanza: $tarima->mudanza);

                return ['serie' => $actual->unsetRelation('incidenciaPendiente'), 'antes' => $antes, 'incidencia' => $incidencia];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['numero_serie' => "La N/S {$capturada} se acaba de registrar en otra captura."]);
        }
    }

    /**
     * Busca la N/S capturada entre los tres números de serie del SKU en el
     * inventario y revisa que no esté ya registrada. Devuelve el valor a
     * guardar (el del inventario si coincidió), el renglón del inventario (o
     * null) y la N/S que ya existía con ese valor (o null). $excepto es la
     * propia N/S cuando se corrige.
     *
     * @return array{0: string, 1: ?InventarioItem, 2: ?NumeroSerie}
     */
    public function validarSerie(InventarioCarga $carga, string $sku, string $capturada, ?NumeroSerie $excepto = null): array {
        $coincidencias = $this->inventario->coincidencias($carga, $sku, $capturada);
        $libre = fn (InventarioItem $i) => ! NumeroSerie::where('inventario_item_id', $i->id)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto->id))->exists();
        // Varias piezas del mismo lote: se toma la primera que aún no se registró.
        $item = $coincidencias->first($libre) ?? $coincidencias->first();
        $valor = $item ? $item->{$item->campo} : NumeroSerieNormalizado::de($capturada);

        $existente = NumeroSerie::with('registro.tarima')
            ->whereIn('numero_serie', array_unique([$valor, $capturada, NumeroSerieNormalizado::de($capturada)]))
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto->id))
            ->first();

        if (! $existente && $item && ! $libre($item)) {
            $usada = NumeroSerie::with('registro.tarima')->where('inventario_item_id', $item->id)->first();
            throw ValidationException::withMessages([
                'numero_serie' => "Ese producto del inventario ya se registró con la N/S {$usada->numero_serie} ({$usada->registro->tarima->nombre}). No se duplicó.",
            ]);
        }

        return [$valor, $item, $existente];
    }

    /**
     * El SKU tal como está en el inventario del almacén (sku, modelo,
     * descripción), o un error claro. Si ese SKU está en el catálogo de
     * conversiones, `sku` es su equivalente y `sku_original` el del inventario;
     * si no, `sku_original` es null. Pedirlo con el SKU ya convertido da lo
     * mismo: la conversión no se aplica dos veces.
     *
     * @return array{sku: string, sku_original: ?string, modelo: ?string, descripcion: ?string, alta: bool}
     */
    public function productoDe(InventarioCarga $carga, string $sku): array {
        $producto = $this->inventario->sku($carga, $sku) ?? throw ValidationException::withMessages([
            'sku' => "El SKU {$sku} no está en el inventario del {$carga->almacen->etiqueta}. Elígelo de la lista.",
        ]);
        $cambio = ConversionSku::cambio($producto['sku']);

        return $cambio === null
            ? $producto + ['sku_original' => null]
            : ['sku' => $cambio, 'sku_original' => $producto['sku']] + $producto;
    }

    /** Sugerencia para una N/S sin coincidencia (p. ej. "existe en otro SKU"). */
    public function pista(InventarioCarga $carga, string $sku, string $capturada): ?string {
        return $this->inventario->pista($carga, $sku, $capturada);
    }

    /** El modelo (SKU padre) del embarque para ese producto del inventario; se crea si no está. */
    public function modeloEn(Tarima $tarima, array $producto, User $usuario): Registro {
        $existente = $tarima->registros()->where('sku', $producto['sku'])->first();
        if ($existente && $existente->tipo !== TipoRegistro::Padre) {
            throw ValidationException::withMessages(['sku' => "El SKU {$producto['sku']} ya está en el {$tarima->nombre} como registro sin N/S (histórico)."]);
        }

        return $existente ?? $tarima->registros()->create([
            'tipo' => TipoRegistro::Padre,
            'sku' => $producto['sku'],
            'sku_original' => $producto['sku_original'] ?? null,
            'descripcion' => $producto['descripcion'] ?? $producto['modelo'],
            'user_id' => $usuario->id,
        ]);
    }

    /**
     * Agregar, editar y eliminar N/S desde "Editar números de serie" solo se
     * permite mientras no se imprima ninguna etiqueta del embarque: una
     * etiqueta pegada debe seguir coincidiendo con lo registrado.
     */
    public function asegurarSinEtiquetas(Tarima $tarima): void {
        if ($tarima->tieneEtiquetasImpresas()) {
            throw ValidationException::withMessages([
                'anomalia' => "Ya se imprimieron etiquetas del {$tarima->nombre}: ya no se agregan, editan ni eliminan N/S desde aquí. Si hay un error, vacía el embarque o corrígelo desde el Historial después de guardarlo.",
            ]);
        }
    }

    private function crearSerie(Registro $padre, string $valor, User $usuario, ?InventarioItem $item = null): NumeroSerie {
        $serie = $padre->numerosSerie()->create([
            'numero_serie' => $valor,
            'user_id' => $usuario->id,
            'inventario_item_id' => $item?->id,
            'coincidencia' => $item?->campo,
        ]);
        $this->tarimas->invalidarImpresion($padre->tarima);

        return $serie;
    }

    private function resultado(string $estado, string $numeroSerie, ?NumeroSerie $serie = null, ?string $coincidencia = null, $incidencia = null, ?string $pista = null): array {
        return compact('estado', 'serie', 'coincidencia', 'incidencia', 'pista') + ['numero_serie' => $numeroSerie];
    }

    public function yaRegistrada(NumeroSerie $existente): string {
        $ubicacion = $existente->registro ? "SKU {$existente->registro->sku} · {$existente->registro->tarima->nombre}" : 'otro registro';
        // Volver a escanearla no toca su marca de anomalía: solo se informa.
        if ($existente->anomalia_at) {
            $ubicacion .= ' · marcada con anomalía';
        }

        return "La N/S {$existente->numero_serie} ya está registrada ({$ubicacion}).";
    }

    /** Inventario vigente del almacén de origen de la tarima, o un error claro si no hay. */
    public function inventarioDe(Tarima $tarima, string $campo): InventarioCarga {
        $almacen = $tarima->loadMissing('movimiento.origen')->almacenOrigen();
        if (! $almacen) {
            throw ValidationException::withMessages([$campo => "El {$tarima->nombre} no tiene almacén de origen (movimiento). Crea un embarque nuevo para el movimiento correcto."]);
        }

        $carga = $this->inventario->vigente($almacen);
        if (! $carga) {
            throw ValidationException::withMessages([$campo => "No hay inventario cargado para el {$almacen->etiqueta}. Pide a supervisión que lo cargue en Inventarios."]);
        }

        return $carga->setRelation('almacen', $almacen);
    }

    /**
     * Marca (o quita la marca de) daño o anomalía en N/S de una tarima en
     * captura. Se eligen por id (casillas) o escaneando la N/S. Una N/S que no
     * es de esta tarima se rechaza: la marca nunca cae en otra pieza.
     *
     * Marcar de nuevo una N/S ya marcada no duplica nada; si trae descripción,
     * se agrega a la que ya tenía (no la sustituye).
     *
     * @param list<int> $ids
     * @return list<string> las N/S afectadas
     */
    public function marcarAnomalias(Tarima $tarima, array $ids, ?string $escaneada, ?string $nota, bool $marcar, User $usuario): array {
        return DB::transaction(function () use ($tarima, $ids, $escaneada, $nota, $marcar, $usuario) {
            $this->asegurarTemporal($tarima, 'anomalia');

            $deLaTarima = NumeroSerie::whereHas('registro', fn ($q) => $q->where('tarima_id', $tarima->id));
            $series = (clone $deLaTarima)->whereIn('id', $ids)->lockForUpdate()->get();
            if (count($ids) !== $series->count()) {
                throw ValidationException::withMessages(['anomalia' => "Una de las N/S elegidas no es del {$tarima->nombre}. Recarga la página."]);
            }

            $escaneada = trim((string) $escaneada);
            if ($escaneada !== '') {
                $serie = (clone $deLaTarima)->where('numero_serie', $escaneada)->lockForUpdate()->first();
                if (! $serie) {
                    $otra = NumeroSerie::with('registro.tarima')->where('numero_serie', $escaneada)->first();
                    throw ValidationException::withMessages(['anomalia' => $otra?->registro
                        ? "La N/S {$escaneada} no es del {$tarima->nombre}: está en el {$otra->registro->tarima->nombre}. No se marcó."
                        : "La N/S {$escaneada} no está en el {$tarima->nombre}. Primero escanéala en su SKU."]);
                }
                $series->push($serie);
            }

            $series = $series->unique('id');
            if ($series->isEmpty()) {
                throw ValidationException::withMessages(['anomalia' => 'Elige o escanea al menos una N/S.']);
            }

            $nota = trim((string) $nota) ?: null;
            foreach ($series as $serie) {
                $serie->update($marcar ? [
                    'anomalia_at' => $serie->anomalia_at ?? now(),
                    'anomalia_by' => $serie->anomalia_by ?? $usuario->id,
                    'anomalia_nota' => match (true) {
                        $nota === null => $serie->anomalia_nota,
                        $serie->anomalia_nota === null => $nota,
                        str_contains($serie->anomalia_nota, $nota) => $serie->anomalia_nota,
                        default => $serie->anomalia_nota."\n".$nota,
                    },
                ] : ['anomalia_at' => null, 'anomalia_by' => null, 'anomalia_nota' => null]);
            }

            $numeros = $series->pluck('numero_serie')->all();
            $this->bitacora->registrar($marcar ? 'anomalia.marcada' : 'anomalia.quitada', $usuario, $tarima, [
                'tarima' => $tarima->codigo, 'numeros_serie' => $numeros, 'nota' => $nota,
            ], mudanza: $tarima->mudanza);

            return $numeros;
        });
    }

    /**
     * Elimina (borrado lógico) N/S elegidas de una tarima en captura, p. ej. una
     * lectura equivocada, mientras no tenga etiquetas impresas (asegurarSinEtiquetas).
     * Solo por id (casillas) y solo de esta tarima. Si la N/S
     * tenía abierta su incidencia de "no encontrado en inventario", se resuelve:
     * la pieza ya no está en el embarque. La N/S queda libre para volver a escanearse.
     *
     * @param list<int> $ids
     * @return list<string> las N/S eliminadas
     */
    public function eliminarNumerosSerie(Tarima $tarima, array $ids, User $usuario): array {
        if ($ids === []) {
            throw ValidationException::withMessages(['anomalia' => 'Elige en la lista al menos una N/S para eliminar.']);
        }

        return DB::transaction(function () use ($tarima, $ids, $usuario) {
            $this->asegurarTemporal($tarima, 'anomalia');
            $this->asegurarSinEtiquetas($tarima);

            $series = NumeroSerie::with('incidenciaPendiente')
                ->whereHas('registro', fn ($q) => $q->where('tarima_id', $tarima->id))
                ->whereIn('id', $ids)->lockForUpdate()->get();
            if (count(array_unique($ids)) !== $series->count()) {
                throw ValidationException::withMessages(['anomalia' => "Una de las N/S elegidas no es del {$tarima->nombre}. Recarga la página."]);
            }

            foreach ($series as $serie) {
                if ($serie->incidenciaPendiente) {
                    $this->incidencias->resolver($serie->incidenciaPendiente, 'corregido_upper',
                        "Se eliminó la N/S {$serie->numero_serie} del {$tarima->nombre} durante la captura.", $usuario);
                }
                $serie->update(['deleted_by' => $usuario->id]);
                $serie->delete();
            }

            $numeros = $series->pluck('numero_serie')->all();
            $this->tarimas->invalidarImpresion($tarima);
            $this->bitacora->registrar('numero_serie.eliminada', $usuario, $tarima, [
                'tarima' => $tarima->codigo, 'numeros_serie' => $numeros,
            ], mudanza: $tarima->mudanza);

            return $numeros;
        });
    }

    /**
     * Se llama dentro de la transacción de la captura: el FOR UPDATE sobre la
     * tarima la serializa con TarimaService::guardar, así que ninguna pieza entra
     * después de que se contó el rango de consecutivos.
     */
    private function asegurarTemporal(Tarima $tarima, string $campo): void {
        $actual = Tarima::whereKey($tarima->id)->lockForUpdate()->first();

        if ($actual && ! $actual->mudanza->permiteCaptura()) {
            throw ValidationException::withMessages([
                $campo => "La {$actual->mudanza->folio} está {$actual->mudanza->estado_nombre}: no admite capturas.",
            ]);
        }

        if ($actual?->estaAnulada()) {
            throw ValidationException::withMessages([
                $campo => "El {$tarima->nombre} está anulado y no admite capturas.",
            ]);
        }

        if ($actual?->estaGuardada() ?? true) {
            throw ValidationException::withMessages([
                $campo => "El {$tarima->nombre} ya fue guardado y no admite más capturas.",
            ]);
        }
    }
}
