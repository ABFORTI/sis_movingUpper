<?php

namespace App\Services;

use App\Models\Embarque;
use App\Models\EmbarquePartida;
use App\Models\Mudanza;
use App\Models\MudanzaMovimiento;
use App\Models\Partida;
use App\Models\Tarima;
use App\Models\User;
use App\Support\Contador;
use App\Support\ConversionSku;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carga (subir al tráiler) y recepción (bajar en el destino).
 *
 * En pantalla este modelo es el "tráiler" (un viaje: placas, sello, salida y
 * recepción); el "embarque" que ve el usuario es la agrupación de piezas
 * (modelo Tarima) que va en la caja del tráiler. Cada pieza tiene una sola
 * fila en `embarque_partidas` con su carga y su recepción, así que no hay dos
 * registros para la misma operación.
 *
 * - En el andén se escanea la etiqueta de cada pieza (código de barras = su
 *   N/S), el número de embarque ("E-004", carga todas sus piezas) o un SKU que
 *   esté en un solo embarque. Las etiquetas viejas de tarima (QR, "TAR-…",
 *   "T-004") se siguen reconociendo. Se rechaza lo que no se puede identificar
 *   sin ambigüedad: embarque anulado, en captura, con etiquetas por
 *   reimprimir, pieza ya cargada en otro tráiler o con una incidencia que
 *   bloquea su traslado. No se pide autorización.
 * - Volver a escanear lo que ya se cargó (o recibió) en este embarque no es un
 *   error: se informa como repetido y no cambia nada (idempotente). Así un
 *   reintento por mala señal no duplica ni asusta al operador.
 * - En el destino, lo que no viene en el embarque no se asocia a nada: se avisa
 *   y queda en la bitácora. Lo cargado y no recibido queda como "Faltante en
 *   recepción" en el manifiesto y el reporte final (ya no abre incidencias:
 *   solo existen las de "no encontrado en inventario" y "producto dañado").
 * - Si el tráiler es de un movimiento (almacén origen → destino), solo se
 *   cargan embarques de ese movimiento.
 */
class EmbarqueService {
    public const OK = 'ok';
    public const REPETIDO = 'repetido';
    public const NO_ESPERADA = 'no_esperada';

    public function __construct(private readonly BitacoraService $bitacora) { }

    public static function claveFolio(Mudanza $mudanza): string {
        return "embarques_mudanza_{$mudanza->id}";
    }

    /**
     * Si la mudanza tiene un solo movimiento, el embarque es de ese; si tiene
     * varios, hay que indicar cuál (cada tráiler va de un almacén a otro).
     *
     * @param array{placas: string, remolque?: ?string, chofer?: ?string, notas?: ?string, movimiento?: ?int} $datos
     */
    public function crear(Mudanza $mudanza, array $datos, User $usuario): Embarque {
        if (! $mudanza->estaEnCurso()) {
            throw ValidationException::withMessages(['placas' => "La {$mudanza->folio} no está en curso."]);
        }

        $movimientos = $mudanza->movimientos()->get();
        $movimiento = isset($datos['movimiento'])
            ? $movimientos->firstWhere('id', (int) $datos['movimiento'])
              ?? throw ValidationException::withMessages(['movimiento' => 'Ese movimiento no es de esta mudanza.'])
            : ($movimientos->count() === 1 ? $movimientos->first() : null);
        if ($movimiento === null && $movimientos->count() > 1) {
            throw ValidationException::withMessages(['movimiento' => 'Elige el movimiento (almacén origen → destino) de este tráiler.']);
        }

        return DB::transaction(function () use ($mudanza, $datos, $usuario, $movimiento) {
            $embarque = Embarque::create([
                'mudanza_id' => $mudanza->id,
                'mudanza_movimiento_id' => $movimiento?->id,
                'folio' => sprintf('TRL-%04d', Contador::siguiente(self::claveFolio($mudanza))),
                'placas' => mb_strtoupper(trim($datos['placas'])),
                'remolque' => $datos['remolque'] ?? null,
                'chofer' => $datos['chofer'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'estado' => Embarque::EN_CARGA,
                'creado_by' => $usuario->id,
            ]);
            $this->bitacora->registrar('embarque.creado', $usuario, $embarque, ['folio' => $embarque->folio, 'placas' => $embarque->placas], mudanza: $mudanza);

            return $embarque;
        });
    }

    /**
     * Escaneo en el andén.
     *
     * @return array{estado: string, mensaje: string, tarima: string, tarima_id: int, piezas: int}
     */
    public function cargar(Embarque $embarque, string $codigo, User $usuario): array {
        try {
            return DB::transaction(fn () => $this->cargarEnTransaccion($embarque, $codigo, $usuario));
        } catch (UniqueConstraintViolationException) {
            // Otro operador cargó la misma pieza en otro embarque al mismo tiempo.
            throw $this->rechazo('Ese embarque o pieza se acaba de cargar en otro tráiler. Revisa la lista.');
        }
    }

    private function cargarEnTransaccion(Embarque $embarque, string $codigo, User $usuario): array {
        $embarque = $this->bloquear($embarque, Embarque::EN_CARGA, 'Este tráiler ya salió: no se le carga nada más.');
        $objetivo = $this->identificar($embarque->mudanza, $codigo);
        $tarima = $objetivo['tarima'];

        $partidas = $objetivo['partidas']
            ?? Partida::enTarima()->where('tarima_id', $tarima->id)->orderBy('consecutivo')->lockForUpdate()->get();

        if ($problema = $this->problemaTarima($tarima)) {
            throw $this->rechazo("{$tarima->codigo}: {$problema}.");
        }
        if ($embarque->mudanza_movimiento_id && $tarima->mudanza_movimiento_id && $tarima->mudanza_movimiento_id !== $embarque->mudanza_movimiento_id) {
            $otro = MudanzaMovimiento::with(['origen', 'destino'])->find($tarima->mudanza_movimiento_id);
            throw $this->rechazo("{$tarima->codigo} es del movimiento {$otro?->nombre}; este tráiler no va a ese destino.");
        }
        if ($partidas->isEmpty()) {
            $trasladadas = $tarima->trasladadaA();
            throw $this->rechazo($trasladadas->isNotEmpty()
                ? "Las piezas del {$tarima->codigo} se movieron al embarque {$trasladadas->join(', ', ' y ')}."
                : "{$tarima->codigo} no tiene piezas.");
        }

        $partidas->load([
            'carga.embarque',
            'incidencias' => fn ($q) => $q->vigentes()->where('bloquea_traslado', true),
        ]);
        $pendientes = $partidas->reject(fn (Partida $p) => $p->carga?->embarque_id === $embarque->id);
        if ($pendientes->isEmpty()) {
            return $this->resultado(self::REPETIDO, "{$objetivo['nombre']}: ya estaba en este tráiler. No se duplicó.", $tarima, $partidas->count());
        }
        if ($problemas = $this->problemasPiezas($pendientes)) {
            throw $this->rechazo("{$objetivo['nombre']} no se puede cargar: ".implode('; ', $problemas).'.');
        }

        $ahora = now();
        EmbarquePartida::insert($pendientes->map(fn (Partida $p) => [
            'embarque_id' => $embarque->id,
            'partida_id' => $p->id,
            'tarima_id' => $p->tarima_id,
            'cargada_at' => $ahora,
            'cargada_by' => $usuario->id,
        ])->values()->all());

        $this->bitacora->registrar('embarque.carga', $usuario, $embarque, [
            'folio' => $embarque->folio,
            'escaneo' => $codigo,
            'tarima' => $tarima->codigo,
            'piezas' => $pendientes->pluck('consecutivo')->all(),
        ], mudanza: $embarque->mudanza);

        $n = $pendientes->count();

        return $this->resultado(self::OK, "{$objetivo['nombre']}: {$n} ".($n === 1 ? 'pieza cargada' : 'piezas cargadas').'.', $tarima, $n);
    }

    /** Bajar del tráiler un embarque cargado por error (solo antes de la salida). */
    public function bajar(Embarque $embarque, Tarima $tarima, string $motivo, User $usuario): int {
        return DB::transaction(function () use ($embarque, $tarima, $motivo, $usuario) {
            $embarque = $this->bloquear($embarque, Embarque::EN_CARGA, 'El tráiler ya salió: no se puede bajar nada.');
            $cargas = $embarque->cargas()->where('tarima_id', $tarima->id)->get();
            if ($cargas->isEmpty()) {
                throw ValidationException::withMessages(['motivo' => "{$tarima->codigo} no está cargado en este tráiler."]);
            }

            EmbarquePartida::whereKey($cargas->modelKeys())->delete();
            $this->bitacora->registrar('embarque.descarga', $usuario, $embarque, [
                'folio' => $embarque->folio, 'tarima' => $tarima->codigo, 'piezas' => $cargas->count(),
            ], $motivo, $embarque->mudanza);

            return $cargas->count();
        });
    }

    /**
     * Salida del tráiler. Se vuelve a revisar cada pieza cargada: pudo retirarse,
     * anularse su embarque o abrirse una incidencia que bloquea después de subirla.
     */
    public function despachar(Embarque $embarque, array $datos, User $usuario): void {
        DB::transaction(function () use ($embarque, $datos, $usuario) {
            $embarque = $this->bloquear($embarque, Embarque::EN_CARGA, 'El tráiler ya salió.');
            $cargas = $embarque->cargas()->with([
                'partida.tarima',
                'partida.incidencias' => fn ($q) => $q->vigentes()->where('bloquea_traslado', true),
            ])->get();

            if ($cargas->isEmpty()) {
                throw ValidationException::withMessages(['salida' => 'El tráiler está vacío.']);
            }

            $problemas = $cargas->map(function (EmbarquePartida $c) {
                $p = $c->partida;
                $motivo = match (true) {
                    $p->estaRetirada() || $p->tarima->estaAnulada() => 'ya no está en un embarque vigente',
                    $p->incidencias->isNotEmpty() => 'incidencia '.$p->incidencias->pluck('folio')->join(', '),
                    default => null,
                };

                return $motivo ? "{$p->tarima->codigo} #{$p->consecutivo}: {$motivo}" : null;
            })->filter();

            if ($problemas->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'salida' => 'No puede salir. Baja estas piezas o resuelve: '.$problemas->take(10)->join('; ').($problemas->count() > 10 ? '…' : '').'.',
                ]);
            }

            $embarque->update([
                'estado' => Embarque::EN_TRANSITO,
                'sello' => $datos['sello'] ?? $embarque->sello,
                'chofer' => $datos['chofer'] ?? $embarque->chofer,
                'salida_at' => now(),
                'salida_by' => $usuario->id,
            ]);
            $this->bitacora->registrar('embarque.salida', $usuario, $embarque, [
                'folio' => $embarque->folio, 'piezas' => $cargas->count(), 'sello' => $embarque->sello,
            ], mudanza: $embarque->mudanza);
        });
    }

    /**
     * Escaneo en el destino.
     *
     * @return array{estado: string, mensaje: string, tarima: string, tarima_id: int, piezas: int}
     */
    public function recibir(Embarque $embarque, string $codigo, User $usuario): array {
        return DB::transaction(function () use ($embarque, $codigo, $usuario) {
            $embarque = $this->bloquear($embarque, Embarque::EN_TRANSITO, $embarque->enCarga() ? 'El tráiler todavía no sale.' : 'La recepción de este tráiler ya se cerró.');
            $objetivo = $this->identificar($embarque->mudanza, $codigo, $embarque);
            $tarima = $objetivo['tarima'];

            $cargas = $embarque->cargas()->lockForUpdate()
                ->when($objetivo['partidas'] === null,
                    fn ($q) => $q->where('tarima_id', $tarima->id),
                    fn ($q) => $q->whereIn('partida_id', $objetivo['partidas']->modelKeys()))
                ->get();

            if ($cargas->isEmpty()) {
                // No se asocia a este embarque; queda el rastro para investigarlo.
                $this->bitacora->registrar('embarque.recepcion_no_esperada', $usuario, $embarque, [
                    'folio' => $embarque->folio, 'escaneo' => $codigo, 'tarima' => $tarima->codigo,
                ], mudanza: $embarque->mudanza);

                return $this->resultado(self::NO_ESPERADA,
                    "{$objetivo['nombre']}: NO viene en el tráiler {$embarque->folio}. No se recibió. Apártalo y avisa a tu supervisor.", $tarima, 0);
            }

            $nuevas = $cargas->whereNull('recibida_at');
            if ($nuevas->isEmpty()) {
                return $this->resultado(self::REPETIDO, "{$objetivo['nombre']}: ya se había recibido. No se duplicó.", $tarima, $cargas->count());
            }

            EmbarquePartida::whereKey($nuevas->modelKeys())->update(['recibida_at' => now(), 'recibida_by' => $usuario->id]);
            $this->bitacora->registrar('embarque.recepcion', $usuario, $embarque, [
                'folio' => $embarque->folio, 'escaneo' => $codigo, 'tarima' => $tarima->codigo, 'piezas' => $nuevas->count(),
            ], mudanza: $embarque->mudanza);

            $n = $nuevas->count();
            $faltan = $embarque->cargas()->where('tarima_id', $tarima->id)->whereNull('recibida_at')->count();

            return $this->resultado(self::OK, "{$objetivo['nombre']}: {$n} ".($n === 1 ? 'pieza recibida' : 'piezas recibidas').'.'
                .($faltan ? " Faltan {$faltan} del {$tarima->codigo}." : ''), $tarima, $n);
        });
    }

    /**
     * Cierra la recepción. Lo cargado y no recibido queda como "Faltante en
     * recepción" en el manifiesto y en el reporte final, y en la bitácora.
     *
     * @return int piezas faltantes
     */
    public function cerrarRecepcion(Embarque $embarque, User $usuario): int {
        return DB::transaction(function () use ($embarque, $usuario) {
            $embarque = $this->bloquear($embarque, Embarque::EN_TRANSITO, 'El tráiler no está en tránsito.');
            $faltantes = $embarque->cargas()->whereNull('recibida_at')->with('partida.tarima')->get()->sortBy('partida.consecutivo');

            $embarque->update(['estado' => Embarque::RECIBIDO, 'recibido_at' => now(), 'recibido_by' => $usuario->id]);
            $this->bitacora->registrar('embarque.recibido', $usuario, $embarque, [
                'folio' => $embarque->folio,
                'faltantes' => $faltantes->count(),
                'consecutivos_faltantes' => $faltantes->map(fn ($c) => $c->partida->consecutivo)->values()->all(),
            ], mudanza: $embarque->mudanza);

            return $faltantes->count();
        });
    }

    /** @return array{piezas: int, sin_cargar: int, cargadas: int, en_transito: int, recibidas: int} */
    public function resumen(Mudanza $mudanza): array {
        $vigentes = Partida::enTarima()->where('mudanza_id', $mudanza->id)->whereHas('tarima', fn ($q) => $q->vigentes());
        $cargas = EmbarquePartida::whereHas('embarque', fn ($q) => $q->where('mudanza_id', $mudanza->id));

        return [
            'piezas' => (clone $vigentes)->count(),
            'sin_cargar' => (clone $vigentes)->doesntHave('carga')->count(),
            'cargadas' => (clone $cargas)->count(),
            'en_transito' => (clone $cargas)->whereNull('recibida_at')->whereHas('embarque', fn ($q) => $q->where('estado', Embarque::EN_TRANSITO))->count(),
            'recibidas' => (clone $cargas)->whereNotNull('recibida_at')->count(),
        ];
    }

    /**
     * Una fila por pieza: cargado vs recibido, para el reporte final (toda la
     * mudanza) o para el manifiesto de un tráiler.
     *
     * @return list<array>
     */
    public function filasReporte(Mudanza $mudanza, ?Embarque $embarque = null): array {
        return Partida::where('mudanza_id', $mudanza->id)
            ->when($embarque,
                fn ($q) => $q->whereHas('carga', fn ($c) => $c->where('embarque_id', $embarque->id)),
                fn ($q) => $q->where(fn ($q) => $q->whereHas('carga')->orWhere(fn ($q) => $q->enTarima()->whereHas('tarima', fn ($t) => $t->vigentes()))))
            ->with([
                'tarima',
                'carga.embarque', 'carga.cargadaPor', 'carga.recibidaPor',
                'incidencias' => fn ($q) => $q->vigentes(),
                'incidenciaPendiente',
            ])
            ->orderBy('consecutivo')
            ->get()
            ->map(function (Partida $p) {
                $c = $p->carga;

                return [
                    $p->consecutivo,
                    $p->sku,
                    $p->descripcion_mostrada,
                    $p->ns_mostrada,
                    $p->tarima->codigo,
                    $c?->embarque->folio,
                    $c?->embarque->placas,
                    $c?->cargada_at?->format('Y-m-d H:i:s'),
                    $c?->cargadaPor?->name,
                    $c?->recibida_at?->format('Y-m-d H:i:s'),
                    $c?->recibidaPor?->name,
                    $this->situacion($p),
                    $p->incidencias->pluck('folio')->join(', ') ?: null,
                    // Fuera del Excel: solo indica qué celda de N/S va en amarillo.
                    'pendiente_revision' => $p->pendienteRevision(),
                ];
            })->all();
    }

    public function situacion(Partida $p): string {
        $c = $p->carga;

        return match (true) {
            $c?->recibida_at !== null => 'Recibida',
            $c?->embarque->estado === Embarque::RECIBIDO => 'Faltante en recepción',
            $c?->embarque->estado === Embarque::EN_TRANSITO => 'En tránsito',
            $c !== null => 'Cargada (sin salir)',
            default => 'Sin cargar',
        };
    }

    private function resultado(string $estado, string $mensaje, Tarima $tarima, int $piezas): array {
        return ['estado' => $estado, 'mensaje' => $mensaje, 'tarima' => $tarima->codigo, 'tarima_id' => $tarima->id, 'piezas' => $piezas];
    }

    private function bloquear(Embarque $embarque, string $estado, string $mensaje): Embarque {
        $actual = Embarque::with('mudanza')->whereKey($embarque->id)->lockForUpdate()->firstOrFail();
        if ($actual->estado !== $estado) {
            throw ValidationException::withMessages(['codigo' => $mensaje]);
        }

        return $actual;
    }

    /**
     * Lo escaneado → embarque completo, o piezas sueltas de un embarque. Acepta
     * la N/S de una pieza (el código de barras de su etiqueta), "E-004" o
     * "MUD-0001 · E-004", un SKU que esté en un solo embarque (en recepción, en
     * uno solo de este tráiler) y, de las etiquetas viejas de tarima, el QR
     * (URL /t/{uuid}), el código de barras (TAR-{id}|fecha) y "T-004".
     *
     * El estado del SKU en la captura (activo o cerrado) no importa: lo que se
     * carga son piezas de tarimas guardadas.
     *
     * @return array{nombre: string, tarima: Tarima, partidas: ?Collection<int, Partida>}
     */
    private function identificar(Mudanza $mudanza, string $codigo, ?Embarque $enEmbarque = null): array {
        // Lo que manda el escáner puede venir alterado: caracteres de control, el
        // prefijo de simbología de DataWedge (]C0, ]Q1) y, si la distribución de
        // teclado no coincide, "-" convertido en "'" o "?" y "|" en "°" o "¬". Por
        // eso los separadores se aceptan como cualquier carácter no alfanumérico.
        $codigo = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $codigo));
        $codigo = (string) preg_replace('/^\][A-Za-z][0-9A-Za-z]/', '', $codigo);
        $sep = '[^0-9a-z]';
        $tarima = match (true) {
            (bool) preg_match("/(?<![0-9a-f])([0-9a-f]{8}){$sep}([0-9a-f]{4}){$sep}([0-9a-f]{4}){$sep}([0-9a-f]{4}){$sep}([0-9a-f]{12})(?![0-9a-f])/iu", $codigo, $m)
                => Tarima::where('uuid', strtolower(implode('-', array_slice($m, 1))))->first(),
            (bool) preg_match("/(?<![0-9a-z])TAR{$sep}{0,3}(\\d+)/iu", $codigo, $m) => Tarima::find((int) $m[1]),
            (bool) preg_match("/(?:^|{$sep})[TE]{$sep}?(\\d{1,6})$/iu", $codigo, $m) => Tarima::where('mudanza_id', $mudanza->id)->where('numero', (int) $m[1])->first(),
            default => null,
        };

        if ($tarima) {
            if ($tarima->mudanza_id !== $mudanza->id) {
                throw $this->rechazo("{$tarima->identificador} es de otra mudanza.");
            }

            return ['nombre' => $tarima->codigo, 'tarima' => $tarima, 'partidas' => null];
        }

        $partida = Partida::with('tarima')->where('mudanza_id', $mudanza->id)->where('numero_serie', $codigo)
            ->orderByRaw('estado = ? desc', [Partida::EN_TARIMA])->latest('id')->first();
        if ($partida) {
            if ($partida->estaRetirada()) {
                throw $this->rechazo("La N/S {$codigo} fue retirada del inventario.");
            }

            return ['nombre' => "N/S {$codigo}", 'tarima' => $partida->tarima, 'partidas' => $partida->newCollection([$partida])];
        }

        return $this->porSku($mudanza, $codigo, $enEmbarque);
    }

    /** Un SKU del catálogo de conversiones también encuentra sus piezas, que quedaron con el SKU convertido. */
    private function porSku(Mudanza $mudanza, string $sku, ?Embarque $enEmbarque): array {
        $partidas = Partida::with('tarima')->where('mudanza_id', $mudanza->id)->whereIn('sku', array_filter([$sku, ConversionSku::cambio($sku)]))->enTarima()
            ->when($enEmbarque,
                fn ($q) => $q->whereHas('carga', fn ($c) => $c->where('embarque_id', $enEmbarque->id)),
                fn ($q) => $q->whereHas('tarima', fn ($t) => $t->vigentes()))
            ->orderBy('consecutivo')->get();

        if ($partidas->isEmpty()) {
            throw $this->rechazo("No se reconoce «{$sku}». Escanea el código de barras de la pieza (su N/S), escribe el número de embarque (E-004) o el SKU.");
        }

        $tarimas = $partidas->pluck('tarima')->unique('id');
        if ($tarimas->count() > 1) {
            throw $this->rechazo("El SKU {$sku} está en varios embarques ({$tarimas->pluck('codigo')->sort()->join(', ')}). Escanea la N/S de cada pieza o escribe el número de embarque.");
        }

        return ['nombre' => "SKU {$sku}", 'tarima' => $tarimas->first(), 'partidas' => $partidas];
    }

    private function problemaTarima(Tarima $tarima): ?string {
        return match (true) {
            $tarima->estaAnulada() => 'está anulado',
            ! $tarima->estaGuardada() => 'sigue en captura (guárdalo primero)',
            $tarima->requiereReimpresion() => 'tiene piezas movidas con etiqueta por reimprimir',
            default => null,
        };
    }

    /** @return list<string> */
    private function problemasPiezas(Collection $partidas): array {
        $porMotivo = [];
        foreach ($partidas as $p) {
            $motivo = match (true) {
                $p->carga !== null => "ya va en el tráiler {$p->carga->embarque->folio}",
                $p->incidencias->isNotEmpty() => 'incidencia '.$p->incidencias->pluck('folio')->join(', '),
                default => null,
            };
            if ($motivo) {
                $porMotivo[$motivo][] = "#{$p->consecutivo}";
            }
        }

        return array_map(
            fn (string $motivo, array $piezas) => count($piezas).' '.(count($piezas) === 1 ? 'pieza' : 'piezas')." {$motivo} (".implode(', ', array_slice($piezas, 0, 8)).(count($piezas) > 8 ? '…' : '').')',
            array_keys($porMotivo),
            $porMotivo,
        );
    }

    private function rechazo(string $mensaje): ValidationException {
        return ValidationException::withMessages(['codigo' => $mensaje]);
    }
}
