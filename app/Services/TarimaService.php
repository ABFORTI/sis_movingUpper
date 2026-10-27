<?php

namespace App\Services;

use App\Enums\TipoRegistro;
use App\Models\Incidencia;
use App\Models\MovimientoPartida;
use App\Models\Mudanza;
use App\Models\MudanzaMovimiento;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use App\Support\Contador;
use App\Support\MudanzaActiva;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class TarimaService {
    public function __construct(
        private readonly BitacoraService $bitacora,
        private readonly MudanzaActiva $mudanzaActiva,
        private readonly IncidenciaService $incidencias,
    ) { }


    public static function claveNumeracion(Mudanza $mudanza): string {
        return "tarimas_mudanza_{$mudanza->id}";
    }


    public static function claveConsecutivo(Mudanza|int $mudanza): string {
        $id = $mudanza instanceof Mudanza ? $mudanza->id : $mudanza;

        return "consecutivo_mudanza_{$id}";
    }


    public function crear(?User $usuario, ?int $movimientoId = null): Tarima {
        $mudanza = $this->mudanzaActiva->obtener();

        if (! $mudanza?->permiteCaptura()) {
            throw ValidationException::withMessages([
                'tarima' => $mudanza
                    ? "La {$mudanza->folio} está {$mudanza->estado_nombre}: no se pueden crear embarques."
                    : 'No hay una mudanza activa. Pide al administrador que active una.',
            ]);
        }

        $movimiento = $this->movimientoPara($mudanza, $movimientoId);

        return DB::transaction(fn () => Tarima::create([
            'mudanza_id' => $mudanza->id,
            'mudanza_movimiento_id' => $movimiento->id,
            'numero' => Contador::siguiente(self::claveNumeracion($mudanza)),
            'created_by' => $usuario?->id,
        ])->setRelation('mudanza', $mudanza)->setRelation('movimiento', $movimiento));
    }

    private function movimientoPara(Mudanza $mudanza, ?int $movimientoId): MudanzaMovimiento {
        $movimientos = $mudanza->movimientos()->with(['origen', 'destino'])->get();

        if ($movimientos->isEmpty()) {
            throw ValidationException::withMessages([
                'movimiento' => "La {$mudanza->folio} no tiene movimientos entre almacenes. Agrégalos en Mudanzas antes de crear embarques.",
            ]);
        }

        $movimiento = $movimientoId !== null
            ? $movimientos->firstWhere('id', $movimientoId)
            : ($movimientos->count() === 1 ? $movimientos->first() : null);

        return $movimiento ?? throw ValidationException::withMessages([
            'movimiento' => $movimientoId !== null
                ? 'Ese movimiento no es de la mudanza activa.'
                : 'Elige el movimiento (almacén origen → destino) del embarque.',
        ]);
    }


    private function asegurarMudanzaOperable(Tarima $tarima): void {
        $mudanza = $tarima->mudanza()->first();

        if (! $mudanza?->permiteCerrarTarimas()) {
            throw ValidationException::withMessages([
                'tarima' => "La {$mudanza?->folio} está {$mudanza?->estado_nombre}: sus embarques no se pueden modificar.",
            ]);
        }
    }


    public function vaciar(Tarima $tarima, User $usuario): void {
        $this->asegurarMudanzaOperable($tarima);


        if (Partida::where('tarima_id', $tarima->id)->enTarima()->exists()) {
            throw ValidationException::withMessages([
                'tarima' => "El {$tarima->nombre} tiene piezas ya registradas que se movieron desde otro embarque. Retíralas desde Corregir.",
            ]);
        }

        DB::transaction(function () use ($tarima, $usuario) {
            $ids = $tarima->registros()->pluck('id');
            $ahora = now();

            $series = NumeroSerie::whereIn('registro_id', $ids)
                ->update(['deleted_at' => $ahora, 'deleted_by' => $usuario->id]);
            Registro::whereIn('id', $ids)
                ->update(['deleted_at' => $ahora, 'deleted_by' => $usuario->id]);

            $this->bitacora->registrar(BitacoraService::TARIMA_VACIADA, $usuario, $tarima, [
                'tarima' => $tarima->id,
                'registros' => $ids->count(),
                'numeros_serie' => $series,
            ]);
        });

        $this->invalidarImpresion($tarima);
        $tarima->unsetRelation('registros');
    }


    public function asegurarImprimible(Tarima $tarima): void {
        abort_if($tarima->estaAnulada(), 422, "El {$tarima->nombre} está anulado.");

        if (! $tarima->estaGuardada()) {
            abort_unless($tarima->mudanza()->first()?->permiteCerrarTarimas(), 422, 'La mudanza no admite cambios en sus embarques.');
        }
    }

    public function invalidarImpresion(Tarima $tarima): void {
        Tarima::whereKey($tarima->id)->whereNull('guardada_at')->update(['impresa_at' => null]);
        $tarima->impresa_at = null;
    }

    /**
     *
     * @return bool true si se guardó ahora; false si ya estaba guardada.
     */
    public function guardar(Tarima $tarima, User $usuario): bool {
        if ($tarima->estaGuardada()) {
            return false;
        }

        if ($tarima->estaAnulada()) {
            throw ValidationException::withMessages([
                'tarima' => "El {$tarima->nombre} está anulado.",
            ]);
        }

        $tarima->unsetRelation('registros');
        if (! $tarima->listaParaGuardar()) {
            throw ValidationException::withMessages([
                'tarima' => $tarima->piezas() === 0
                    ? "El {$tarima->nombre} no tiene piezas."
                    : "Primero imprime las etiquetas de las piezas del {$tarima->nombre}.",
            ]);
        }

        $this->asegurarMudanzaOperable($tarima);

        $guardadaAhora = DB::transaction(function () use ($tarima, $usuario) {
            $actual = Tarima::whereKey($tarima->id)->lockForUpdate()->firstOrFail();

            if ($actual->estaGuardada() || $actual->estaAnulada() || ! $actual->listaParaGuardar()) {
                return false;
            }

            $this->descartarSkusSinPiezas($actual, $usuario);
            Tarima::whereKey($actual->id)->update([
                'guardada_at' => now(),
                'guardada_by' => $usuario->id,
                'consecutivo_inicial' => $this->materializarPartidas($actual, $usuario),
            ]);
            $this->registrarAnomalias($actual, $usuario);
            $this->incidencias->vincularPiezasGuardadas($actual, $usuario);

            return true;
        });

        $tarima->refresh();

        if (! $guardadaAhora && ! $tarima->estaGuardada()) {
            throw ValidationException::withMessages([
                'tarima' => "El contenido del {$tarima->nombre} cambió. Imprime las etiquetas de las piezas nuevas.",
            ]);
        }

        return $guardadaAhora;
    }


    public function actualizarAdicional(Tarima $tarima, ?User $usuario = null): void {
        $destinos = $tarima->estaGuardada() && ! $tarima->estaAnulada() && ! $tarima->partidas()->exists()
            ? $tarima->traslados()->distinct()->pluck('tarima_destino_id')
            : collect();
        $adicionalDe = $destinos->count() === 1 ? $destinos->first() : null;

        if ($tarima->adicional_de_id !== $adicionalDe) {
            $antes = $tarima->codigo;
            $tarima->forceFill(['adicional_de_id' => $adicionalDe])->save();
            $tarima->unsetRelation('adicionalDe');
            $this->bitacora->registrar('tarima.renombrada', $usuario, $tarima, ['antes' => $antes, 'despues' => $tarima->codigo]);
        }
    }


    public function piezasSinPartida(Tarima $tarima): int {
        if (! $tarima->estaGuardada() || $tarima->estaAnulada()) {
            return 0;
        }

        return NumeroSerie::whereHas('registro', fn ($q) => $q->where('tarima_id', $tarima->id))
            ->whereDoesntHave('partida')->count();
    }

    /**
     *
     * @return int piezas a las que se les creó su partida
     */
    public function completarPartidas(Tarima $tarima, User $usuario): int {
        if (! $tarima->estaGuardada() || $tarima->estaAnulada()) {
            throw ValidationException::withMessages(['tarima' => "El {$tarima->nombre} no está guardado o está anulado: no hay partidas que completar."]);
        }

        return DB::transaction(function () use ($tarima, $usuario) {
            $actual = Tarima::whereKey($tarima->id)->lockForUpdate()->firstOrFail();
            $faltan = $this->piezasSinPartida($actual);
            if ($faltan === 0) {
                return 0;
            }

            $this->descartarSkusSinPiezas($actual, $usuario);
            $inicial = $this->materializarPartidas($actual, $usuario);
            $sinEtiqueta = NumeroSerie::whereHas('registro', fn ($q) => $q->where('tarima_id', $actual->id))->whereNull('etiqueta_impresa_at')->count();
            $cambios = array_filter([
                'consecutivo_inicial' => $actual->consecutivo_inicial === null ? $inicial : null,
                'reimpresion_pendiente_at' => $sinEtiqueta > 0 ? now() : null,
            ]);
            if ($cambios !== []) {
                Tarima::whereKey($actual->id)->update($cambios);
            }
            $this->registrarAnomalias($actual, $usuario);
            $this->incidencias->vincularPiezasGuardadas($actual, $usuario);

            $this->bitacora->registrar('tarima.partidas_completadas', $usuario, $actual, [
                'tarima' => $actual->codigo,
                'piezas' => $faltan,
                'consecutivos' => [$inicial, $inicial + $faltan - 1],
                'etiquetas_sin_confirmar' => $sinEtiqueta,
            ], 'Embarque guardado sin partidas: se completaron con la lógica del guardado.');
            $tarima->refresh();

            return $faltan;
        });
    }


    private function descartarSkusSinPiezas(Tarima $tarima, User $usuario): void {
        $vacios = $tarima->registros()->where('tipo', TipoRegistro::Padre)->doesntHave('numerosSerie')->pluck('id');
        if ($vacios->isNotEmpty()) {
            Registro::whereKey($vacios)->update(['deleted_at' => now(), 'deleted_by' => $usuario->id]);
            $tarima->unsetRelation('registros');
        }
    }

    /**
     *
     * @return int incidencias creadas
     */
    private function registrarAnomalias(Tarima $tarima, User $usuario): int {
        $marcadas = NumeroSerie::with('registro')
            ->whereHas('registro', fn ($q) => $q->where('tarima_id', $tarima->id))
            ->whereNotNull('anomalia_at')
            ->whereNotExists(fn ($q) => $q->from('incidencias')->whereColumn('incidencias.numero_serie_id', 'numeros_serie.id')->where('tipo', Incidencia::DANIO))
            ->orderBy('id')->get();

        foreach ($marcadas as $serie) {
            $partida = Partida::where('numero_serie_id', $serie->id)->firstOrFail();
            $reporto = ($serie->anomalia_by ? User::find($serie->anomalia_by) : null) ?? $usuario;

            $this->incidencias->crear($tarima->mudanza, [
                'tipo' => Incidencia::DANIO,
                'titulo' => "Producto dañado: N/S {$serie->numero_serie} ({$tarima->codigo})",
                'descripcion' => ($serie->anomalia_nota ?? 'Marcada en la captura, sin descripción.')
                    ."\n\nSKU {$serie->registro->sku}. Marcada el {$serie->anomalia_at->format('d/m/Y H:i')}.",
                'bloquea_traslado' => false,
                'numero_serie_id' => $serie->id,
                'tarima_id' => $tarima->id,
                'almacen_id' => $tarima->movimiento?->almacen_origen_id,
                'sku' => $serie->registro->sku,
                'numero_serie' => $serie->numero_serie,
            ], $reporto, partidas: $partida->newCollection([$partida]));
        }

        return $marcadas->count();
    }

    private function materializarPartidas(Tarima $tarima, User $usuario): int {
        $nuevas = $this->filas($tarima)->reject(fn (array $f) => $f['numero_serie_id']
            ? Partida::where('numero_serie_id', $f['numero_serie_id'])->exists()
            : Partida::where('registro_id', $f['registro_id'])->whereNull('numero_serie_id')->exists()
        )->values();

        $inicial = $this->reservarConsecutivos($tarima, $nuevas->count());

        foreach ($nuevas as $posicion => $f) {
            $partida = Partida::create([
                'mudanza_id' => $tarima->mudanza_id,
                'tarima_id' => $tarima->id,
                'registro_id' => $f['registro_id'],
                'numero_serie_id' => $f['numero_serie_id'],
                'consecutivo' => $inicial + $posicion,
                'tipo_identificacion' => $f['tipo'],
                'sku' => $f['sku'],
                'descripcion' => $f['descripcion_original'],
                'demo' => $f['demo'],
                'numero_serie' => $f['numero_serie_id'] ? $f['ns'] : null,
                'destino_id' => $f['destino_id'],
                'registrado_at' => $f['registrado'],
                'estado' => Partida::EN_TARIMA,
            ]);
            $partida->movimientos()->create([
                'tipo' => MovimientoPartida::ALTA,
                'tarima_destino_id' => $tarima->id,
                'user_id' => $usuario->id,
            ]);
        }

        return $inicial;
    }


    private function reservarConsecutivos(Tarima $tarima, int $cantidad): int {
        return Contador::reservar(self::claveConsecutivo($tarima->mudanza_id), max(1, $cantidad));
    }

    /**
     *
     * @return bool true si su número se asignará al siguiente embarque
     */
    public function eliminar(Tarima $tarima, User $usuario, string $motivo): bool {
        $this->asegurarAnulable($tarima);

        if (Partida::where('tarima_id', $tarima->id)->exists()) {
            throw ValidationException::withMessages([
                'motivo' => "El {$tarima->nombre} tiene piezas ya registradas que llegaron desde otro embarque: no se puede eliminar. Retíralas o muévelas desde Corregir.",
            ]);
        }

        return DB::transaction(function () use ($tarima, $usuario, $motivo) {
            $actual = Tarima::with('mudanza')->whereKey($tarima->id)->lockForUpdate()->first();
            if (! $actual || $actual->estaGuardada() || $actual->estaAnulada()) {
                throw ValidationException::withMessages(['motivo' => "El {$tarima->nombre} cambió mientras tanto. Recarga la página."]);
            }

            $registros = Registro::withTrashed()->where('tarima_id', $actual->id)->pluck('id');
            $series = NumeroSerie::withTrashed()->with('incidenciaPendiente')->whereIn('registro_id', $registros)->get();
            $vivas = $series->whereNull('deleted_at');

            foreach ($vivas as $serie) {
                if ($serie->incidenciaPendiente) {
                    $this->incidencias->resolver($serie->incidenciaPendiente, 'corregido_upper',
                        "Se eliminó el {$actual->nombre} (anulado en captura) con la N/S {$serie->numero_serie}.", $usuario);
                }
            }

            Incidencia::where('tarima_id', $actual->id)->update(['tarima_id' => null]);
            Incidencia::whereIn('numero_serie_id', $series->modelKeys())->update(['numero_serie_id' => null]);

            $reutiliza = Contador::liberarUltimo(self::claveNumeracion($actual->mudanza), $actual->numero);

            $this->bitacora->registrar(BitacoraService::TARIMA_ELIMINADA, $usuario, $actual, [
                'tarima' => $actual->codigo,
                'numero' => $actual->numero,
                'creada_por' => $actual->created_by,
                'piezas' => $vivas->count(),
                'numeros_serie' => $vivas->pluck('numero_serie')->all(),
                'numero_reutilizable' => $reutiliza,
            ], $motivo);


            $actual->delete();

            return $reutiliza;
        });
    }

    private function asegurarAnulable(Tarima $tarima): void {
        $mudanza = $tarima->mudanza()->first();
        if (in_array($mudanza?->estado, [Mudanza::CERRADA, Mudanza::CANCELADA], true)) {
            throw ValidationException::withMessages([
                'motivo' => "La {$mudanza->folio} está {$mudanza->estado_nombre}: sus embarques ya no se anulan.",
            ]);
        }
    }

    /**
     *
     * @return bool true si se anuló ahora; false si ya estaba anulada.
     */
    public function anular(Tarima $tarima, User $usuario, string $motivo): bool {
        $this->asegurarAnulable($tarima);

        if (Partida::where('tarima_id', $tarima->id)->has('carga')->exists()) {
            throw ValidationException::withMessages(['motivo' => "El {$tarima->nombre} ya se cargó en un tráiler: no se puede anular."]);
        }

        $anuladaAhora = DB::transaction(function () use ($tarima, $usuario, $motivo) {
            $actual = Tarima::whereKey($tarima->id)->lockForUpdate()->firstOrFail();
            if ($actual->estaAnulada()) {
                return false;
            }

            Tarima::whereKey($actual->id)->update([
                'anulada_at' => now(),
                'anulada_by' => $usuario->id,
                'motivo_anulacion' => $motivo,
            ]);

            $this->bitacora->registrar(BitacoraService::TARIMA_ANULADA, $usuario, $actual, [
                'tarima' => $actual->id,
                'estaba_guardada' => $actual->estaGuardada(),
                'estaba_impresa' => $actual->estaImpresa(),
                'consecutivo_inicial' => $actual->consecutivo_inicial,
                'piezas' => $this->filas($actual)->count(),
            ], $motivo);

            return true;
        });

        $tarima->refresh();

        return $anuladaAhora;
    }

    /**
     *
     * @return Collection<int, array{sku: string, sku_original: ?string, ns: string, descripcion: string, fecha: string, hora: string, registrado: string, tipo: string, registro_id: int, numero_serie_id: ?int, destino_id: ?int, descripcion_original: ?string, pendiente_revision: bool}>
     */
    public function filas(Tarima $tarima): Collection {
        $tarima->cargarDetalle();

        return $tarima->registros->flatMap(function (Registro $r) {
            $base = [
                'sku' => $r->sku,
                'sku_original' => $r->sku_original,
                'descripcion' => $r->descripcion_mostrada,
                'descripcion_original' => $r->descripcion,
                'demo' => $r->demo,
                'destino_id' => $r->destino_id,
                'fecha' => $r->created_at->format('Y-m-d'),
                'registro_id' => $r->id,
                'numero_serie_id' => null,
            ];
            $sinSerie = fn (string $ns, string $tipo) => [$base + [
                'ns' => $ns, 'tipo' => $tipo, 'pendiente_revision' => false,
                'hora' => $r->created_at->format('H:i:s'), 'registrado' => $r->created_at->format('Y-m-d H:i:s'),
            ]];

            if ($r->tipo === TipoRegistro::Simple) {
                return $sinSerie('—', Partida::TIPO_SKU);
            }

            if ($r->numerosSerie->isEmpty()) {
                return $sinSerie('(sin N/S)', Partida::TIPO_SIN_NS);
            }

            return $r->numerosSerie->map(fn (NumeroSerie $ns) => [
                'ns' => $ns->numero_serie,
                'tipo' => Partida::TIPO_NS,
                'numero_serie_id' => $ns->id,
                'pendiente_revision' => $ns->pendienteRevision(),
                'hora' => $ns->created_at->format('H:i:s'),
                'registrado' => $ns->created_at->format('Y-m-d H:i:s'),
            ] + $base);
        })->values();
    }


    public function destino(Tarima $tarima): string {
        $tarima->cargarDetalle();

        return $tarima->movimiento?->destino->codigo
            ?? $tarima->registros->first()?->destino?->codigo
            ?? 'N/A';
    }

    public function urlDetalle(Tarima $tarima): string {
        return URL::signedRoute('tarimas.publico', ['tarima' => $tarima->uuid]);
    }
}
