<?php

namespace App\Services;

use App\Enums\TipoRegistro;
use App\Models\MovimientoPartida;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use App\Support\Contador;
use App\Support\NumeroSerieNormalizado;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correcciones sobre tarimas ya guardadas: mover piezas a otra tarima o
 * retirarlas. La partida conserva su consecutivo (lo que el cliente ya recibió
 * en el Excel) y cada cambio queda en movimientos_partida y en la bitácora.
 *
 * También se ajusta la capa de captura (registros / numeros_serie) para que las
 * etiquetas, el historial y la consulta muestren lo que físicamente hay en cada
 * embarque. Cada pieza lleva su propia etiqueta con el número de embarque: una
 * pieza movida necesita etiqueta nueva (su embarque destino queda con
 * "reimpresión pendiente" hasta imprimirla); retirar piezas no cambia las
 * etiquetas de las que se quedan.
 */
class PartidaService {
    public function __construct(
        private readonly TarimaService $tarimas,
        private readonly BitacoraService $bitacora,
        private readonly EscaneoService $escaneo,
        private readonly IncidenciaService $incidencias,
    ) { }

    /** @param list<int> $ids */
    public function mover(Tarima $origen, array $ids, Tarima $destino, User $usuario, string $motivo): int {
        $this->validarOrigen($origen);

        if ($destino->is($origen)) {
            throw ValidationException::withMessages(['destino' => 'Elige un embarque distinto al de origen.']);
        }
        if ($destino->mudanza_id !== $origen->mudanza_id || $destino->estaAnulada()) {
            throw ValidationException::withMessages(['destino' => 'El embarque destino debe ser de la misma mudanza y no estar anulado.']);
        }

        return DB::transaction(function () use ($origen, $ids, $destino, $usuario, $motivo) {
            $this->bloquear($origen, $destino);
            $partidas = $this->partidasDe($origen, $ids);

            foreach ($partidas as $partida) {
                $this->moverCaptura($partida, $destino, $usuario);
                $partida->update(['tarima_id' => $destino->id]);
                $partida->movimientos()->create([
                    'tipo' => MovimientoPartida::CAMBIO_TARIMA,
                    'tarima_origen_id' => $origen->id,
                    'tarima_destino_id' => $destino->id,
                    'motivo' => $motivo,
                    'user_id' => $usuario->id,
                ]);
            }

            // La etiqueta pegada en cada pieza movida trae el número del embarque anterior.
            NumeroSerie::whereKey($partidas->pluck('numero_serie_id')->filter()->all())->update(['etiqueta_impresa_at' => null]);
            $this->pedirReimpresion($destino);

            $this->bitacora->registrar('partidas.movidas', $usuario, $origen, [
                'de' => $origen->identificador,
                'a' => $destino->identificador,
                'consecutivos' => $partidas->pluck('consecutivo')->all(),
            ], $motivo);

            return $partidas->count();
        });
    }

    /**
     * Retira piezas del embarque (salen de la mudanza). Con NS_ELIMINADA es
     * "Eliminar N/S": la N/S se capturó por error; misma salida del inventario,
     * pero queda registrada como eliminación y su incidencia de "no encontrado
     * en inventario", si la tenía, se resuelve.
     *
     * @param list<int> $ids
     */
    public function retirar(Tarima $origen, array $ids, User $usuario, string $motivo, string $tipo = MovimientoPartida::RETIRO): int {
        $this->validarOrigen($origen);
        $eliminar = $tipo === MovimientoPartida::NS_ELIMINADA;

        return DB::transaction(function () use ($origen, $ids, $usuario, $motivo, $tipo, $eliminar) {
            $this->bloquear($origen);
            $partidas = $this->partidasDe($origen, $ids);
            if ($eliminar && $partidas->contains(fn (Partida $p) => $p->numero_serie_id === null)) {
                throw ValidationException::withMessages(['partidas' => 'Eliminar N/S solo aplica a piezas con número de serie. Para las demás usa «Retirar seleccionadas».']);
            }
            $ahora = now();

            foreach ($partidas as $partida) {
                if ($eliminar && $partida->incidenciaPendiente) {
                    $this->incidencias->resolver($partida->incidenciaPendiente, 'corregido_upper',
                        "Se eliminó la N/S {$partida->numero_serie} (#{$partida->consecutivo}) del {$origen->nombre}: {$motivo}", $usuario);
                }
                // La N/S se libera: si la pieza aparece de nuevo se escanea y recibe un consecutivo nuevo.
                if ($partida->numero_serie_id) {
                    NumeroSerie::whereKey($partida->numero_serie_id)->update(['deleted_at' => $ahora, 'deleted_by' => $usuario->id]);
                    $this->limpiarRegistroVacio($partida->registro_id, $usuario);
                } else {
                    Registro::whereKey($partida->registro_id)->update(['deleted_at' => $ahora, 'deleted_by' => $usuario->id]);
                }

                $partida->forceFill(['estado' => Partida::RETIRADA, 'retirada_at' => $ahora, 'retirada_by' => $usuario->id])->save();
                $partida->movimientos()->create([
                    'tipo' => $tipo,
                    'tarima_origen_id' => $origen->id,
                    'motivo' => $motivo,
                    'detalle' => $eliminar ? "SKU {$partida->sku} · N/S {$partida->numero_serie}" : null,
                    'user_id' => $usuario->id,
                ]);
            }

            $this->bitacora->registrar($eliminar ? 'numeros_serie.eliminadas' : 'partidas.retiradas', $usuario, $origen, [
                'tarima' => $origen->identificador,
                'consecutivos' => $partidas->pluck('consecutivo')->all(),
                'numeros_serie' => $partidas->pluck('numero_serie')->filter()->values()->all(),
            ], $motivo);

            return $partidas->count();
        });
    }

    /**
     * "Agregar N/S a este modelo" en un embarque guardado: una pieza que faltó
     * registrar. Se valida igual que en el escaneo (inventario del almacén de
     * origen, sin duplicados; sin coincidencia abre su incidencia) y recibe el
     * siguiente consecutivo de la mudanza. Necesita su etiqueta: el embarque
     * queda con reimpresión pendiente hasta imprimirla.
     */
    public function agregar(Tarima $tarima, Registro $modelo, string $capturada, User $usuario, string $motivo): NumeroSerie {
        $this->validarOrigen($tarima);
        if ($modelo->tarima_id !== $tarima->id || $modelo->tipo !== TipoRegistro::Padre) {
            throw ValidationException::withMessages(['modelo' => "Elige un modelo (SKU) del {$tarima->nombre}."]);
        }
        $capturada = NumeroSerieNormalizado::limpio($capturada);
        if ($capturada === '') {
            throw ValidationException::withMessages(['numero_serie' => 'Escanea o escribe la N/S.']);
        }
        $carga = $this->escaneo->inventarioDe($tarima, 'numero_serie');

        try {
            return DB::transaction(function () use ($tarima, $modelo, $carga, $capturada, $usuario, $motivo) {
                $this->bloquear($tarima);
                [$valor, $item, $existente] = $this->escaneo->validarSerie($carga, $modelo->sku, $capturada);
                if ($existente) {
                    throw ValidationException::withMessages(['numero_serie' => $this->escaneo->yaRegistrada($existente)]);
                }

                $serie = $modelo->numerosSerie()->create([
                    'numero_serie' => $valor,
                    'user_id' => $usuario->id,
                    'inventario_item_id' => $item?->id,
                    'coincidencia' => $item?->campo,
                ]);
                $partida = Partida::create([
                    'mudanza_id' => $tarima->mudanza_id,
                    'tarima_id' => $tarima->id,
                    'registro_id' => $modelo->id,
                    'numero_serie_id' => $serie->id,
                    'consecutivo' => Contador::reservar(TarimaService::claveConsecutivo($tarima->mudanza_id)),
                    'tipo_identificacion' => Partida::TIPO_NS,
                    'sku' => $modelo->sku,
                    'descripcion' => $modelo->descripcion,
                    'numero_serie' => $valor,
                    'destino_id' => $modelo->destino_id,
                    'registrado_at' => $serie->created_at,
                    'estado' => Partida::EN_TARIMA,
                ]);
                $partida->movimientos()->create([
                    'tipo' => MovimientoPartida::NS_AGREGADA,
                    'tarima_destino_id' => $tarima->id,
                    'motivo' => $motivo,
                    'detalle' => "SKU {$modelo->sku} · N/S {$valor}",
                    'user_id' => $usuario->id,
                ]);

                if (! $item) {
                    $this->incidencias->crearNoEncontrada($serie->setRelation('registro', $modelo), $tarima, $usuario,
                        $this->escaneo->pista($carga, $modelo->sku, $capturada), collect([$partida]));
                }
                $this->pedirReimpresion($tarima);

                $this->bitacora->registrar('numero_serie.agregada', $usuario, $tarima, [
                    'tarima' => $tarima->identificador, 'sku' => $modelo->sku, 'numero_serie' => $valor, 'consecutivo' => $partida->consecutivo,
                ], $motivo);

                return $serie;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['numero_serie' => "La N/S {$capturada} se acaba de registrar en otra captura."]);
        }
    }

    /**
     * "Editar N/S" en un embarque guardado: corrige el número de serie, el SKU o
     * ambos. La pieza conserva su consecutivo; si cambia el SKU pasa a ese
     * modelo dentro del mismo embarque. Se valida igual que una lectura nueva y
     * el cambio queda en su movimiento (antes → después). Su etiqueta ya no
     * coincide: el embarque queda con reimpresión pendiente.
     */
    public function editar(Tarima $tarima, int $partidaId, string $sku, string $capturada, User $usuario, string $motivo): NumeroSerie {
        $this->validarOrigen($tarima);
        $capturada = NumeroSerieNormalizado::limpio($capturada);
        if ($capturada === '') {
            throw ValidationException::withMessages(['numero_serie' => 'Escribe la N/S.']);
        }
        $carga = $this->escaneo->inventarioDe($tarima, 'sku');
        $producto = $this->escaneo->productoDe($carga, $sku);

        try {
            return DB::transaction(function () use ($tarima, $partidaId, $producto, $carga, $capturada, $usuario, $motivo) {
                $this->bloquear($tarima);
                $partida = $this->partidasDe($tarima, [$partidaId])->first();
                if ($partida->numero_serie_id === null) {
                    throw ValidationException::withMessages(['partidas' => 'Solo se editan piezas con número de serie.']);
                }
                $serie = NumeroSerie::with('incidenciaPendiente')->findOrFail($partida->numero_serie_id);
                $antes = "SKU {$partida->sku} · N/S {$partida->numero_serie}";

                [$valor, $item, $existente] = $this->escaneo->validarSerie($carga, $producto['sku'], $capturada, $serie);
                if ($existente) {
                    throw ValidationException::withMessages(['numero_serie' => $this->escaneo->yaRegistrada($existente)]);
                }
                if ($partida->sku === $producto['sku'] && $partida->numero_serie === $valor) {
                    throw ValidationException::withMessages(['numero_serie' => 'No hay cambios: la N/S y el SKU son los mismos.']);
                }

                $modelo = $this->escaneo->modeloEn($tarima, $producto, $usuario);
                $registroAnterior = $serie->registro_id;
                $serie->update([
                    'registro_id' => $modelo->id,
                    'numero_serie' => $valor,
                    'inventario_item_id' => $item?->id,
                    'coincidencia' => $item?->campo,
                    'etiqueta_impresa_at' => null,
                ]);
                if ($registroAnterior !== $modelo->id) {
                    $this->limpiarRegistroVacio($registroAnterior, $usuario);
                }
                $partida->update([
                    'registro_id' => $modelo->id,
                    'sku' => $modelo->sku,
                    'descripcion' => $modelo->descripcion,
                    'numero_serie' => $valor,
                ]);
                $detalle = "{$antes} → SKU {$modelo->sku} · N/S {$valor}";
                $partida->movimientos()->create([
                    'tipo' => MovimientoPartida::NS_EDITADA,
                    'tarima_origen_id' => $tarima->id,
                    'tarima_destino_id' => $tarima->id,
                    'motivo' => $motivo,
                    'detalle' => mb_strimwidth($detalle, 0, 255, '…'),
                    'user_id' => $usuario->id,
                ]);

                if ($serie->incidenciaPendiente) {
                    $this->incidencias->resolver($serie->incidenciaPendiente, 'corregido_upper', "Se corrigió la pieza #{$partida->consecutivo}: {$detalle}.", $usuario);
                }
                if (! $item) {
                    $this->incidencias->crearNoEncontrada($serie->unsetRelation('incidenciaPendiente')->setRelation('registro', $modelo), $tarima, $usuario,
                        $this->escaneo->pista($carga, $modelo->sku, $capturada), collect([$partida]));
                }
                $this->pedirReimpresion($tarima);

                $this->bitacora->registrar('numero_serie.editada', $usuario, $tarima, [
                    'tarima' => $tarima->identificador, 'consecutivo' => $partida->consecutivo, 'cambio' => $detalle,
                ], $motivo);

                return $serie;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['numero_serie' => "La N/S {$capturada} se acaba de registrar en otra captura."]);
        }
    }

    private function validarOrigen(Tarima $origen): void {
        if (! $origen->estaGuardada() || $origen->estaAnulada()) {
            throw ValidationException::withMessages(['partidas' => 'Solo se corrigen embarques guardados y no anulados.']);
        }
        $mudanza = $origen->mudanza()->first();
        if (! $mudanza?->permiteCerrarTarimas()) {
            throw ValidationException::withMessages(['partidas' => "La {$mudanza?->folio} está {$mudanza?->estado_nombre}: no admite correcciones."]);
        }
    }

    /** Bloquea los embarques en orden de id para que dos correcciones cruzadas no se traben. */
    private function bloquear(Tarima ...$tarimas): void {
        Tarima::whereKey(array_map(fn (Tarima $t) => $t->id, $tarimas))->orderBy('id')->lockForUpdate()->get();
    }

    /** @return Collection<int, Partida> */
    private function partidasDe(Tarima $origen, array $ids): Collection {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $partidas = Partida::whereKey($ids)->where('tarima_id', $origen->id)->enTarima()->lockForUpdate()->orderBy('consecutivo')->get();

        if ($ids === [] || $partidas->count() !== count($ids)) {
            throw ValidationException::withMessages(['partidas' => 'Selecciona piezas que sigan en este embarque (otra persona pudo haberlas movido).']);
        }

        $cargadas = $partidas->load('carga.embarque')->filter(fn (Partida $p) => $p->carga !== null);
        if ($cargadas->isNotEmpty()) {
            throw ValidationException::withMessages([
                'partidas' => 'Estas piezas ya se cargaron ('.$cargadas->map(fn (Partida $p) => "#{$p->consecutivo} en {$p->carga->embarque->folio}")->join(', ').'). Para corregirlas, primero bájalas del tráiler.',
            ]);
        }

        return $partidas;
    }

    /**
     * La pieza cambia de tarima también en la captura. Una N/S pasa al SKU padre
     * del mismo SKU en el destino (se crea si no existe); un SKU sin N/S mueve su
     * registro completo.
     */
    private function moverCaptura(Partida $partida, Tarima $destino, User $usuario): void {
        $existente = Registro::where('tarima_id', $destino->id)->where('sku', $partida->sku)->first();

        if ($partida->numero_serie_id === null) {
            if ($existente) {
                throw ValidationException::withMessages([
                    'destino' => "El {$destino->nombre} ya tiene el SKU {$partida->sku}; no se puede juntar con un SKU sin N/S.",
                ]);
            }
            Registro::whereKey($partida->registro_id)->update(['tarima_id' => $destino->id]);

            return;
        }

        if ($existente && $existente->tipo !== TipoRegistro::Padre) {
            throw ValidationException::withMessages([
                'destino' => "En el {$destino->nombre} el SKU {$partida->sku} está como SKU suelto; no puede recibir piezas con N/S.",
            ]);
        }

        $registroDestino = $existente ?? Registro::create([
            'tarima_id' => $destino->id,
            'tipo' => TipoRegistro::Padre,
            'sku' => $partida->sku,
            'descripcion' => $partida->descripcion,
            'destino_id' => $partida->destino_id,
            'user_id' => $usuario->id,
        ]);

        $registroOrigen = $partida->registro_id;
        NumeroSerie::whereKey($partida->numero_serie_id)->update(['registro_id' => $registroDestino->id]);
        $partida->registro_id = $registroDestino->id;
        $this->limpiarRegistroVacio($registroOrigen, $usuario);
    }

    /** Un SKU padre que se quedó sin N/S ya no está físicamente en la tarima. */
    private function limpiarRegistroVacio(int $registroId, User $usuario): void {
        if (! NumeroSerie::where('registro_id', $registroId)->exists()) {
            Registro::whereKey($registroId)->update(['deleted_at' => now(), 'deleted_by' => $usuario->id]);
        }
    }

    private function pedirReimpresion(Tarima $tarima): void {
        if ($tarima->estaGuardada()) {
            Tarima::whereKey($tarima->id)->update(['reimpresion_pendiente_at' => now()]);
        } else {
            $this->tarimas->invalidarImpresion($tarima);
        }
    }
}
