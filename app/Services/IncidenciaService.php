<?php

namespace App\Services;

use App\Models\Incidencia;
use App\Models\IncidenciaEvento;
use App\Models\Mudanza;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Tarima;
use App\Models\User;
use App\Support\BuscadorPiezas;
use App\Support\Contador;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;


class IncidenciaService {
    public function __construct(
        private readonly DocumentoService $documentos,
        private readonly BitacoraService $bitacora,
    ) { }

    public static function claveFolio(Mudanza $mudanza): string {
        return "incidencias_mudanza_{$mudanza->id}";
    }

    /**
     * @param array{tipo: string, titulo: string, descripcion?: ?string, bloquea_traslado?: bool, piezas?: ?string, numero_serie_id?: ?int, tarima_id?: ?int, almacen_id?: ?int, sku?: ?string, numero_serie?: ?string} $datos
     * @param list<UploadedFile> $archivos
     * @param ?Collection<int, Partida> $partidas piezas ya identificadas (en lugar del texto de `piezas`)
     */
    public function crear(Mudanza $mudanza, array $datos, User $usuario, array $archivos = [], ?Collection $partidas = null): Incidencia {
        if (! isset(Incidencia::TIPOS[$datos['tipo']])) {
            throw new LogicException("Tipo de incidencia no operativo: {$datos['tipo']}.");
        }
        if (! $mudanza->estaEnCurso()) {
            throw ValidationException::withMessages(['titulo' => "La {$mudanza->folio} no está en curso."]);
        }
        $partidas ??= BuscadorPiezas::resolver($mudanza, $datos['piezas'] ?? null);


        if ($datos['tipo'] === Incidencia::NO_EN_INVENTARIO && empty($datos['numero_serie_id'])) {
            $pieza = $partidas->count() === 1 ? $partidas->first()->loadMissing('tarima.movimiento') : null;
            if (! $pieza?->numero_serie_id) {
                throw ValidationException::withMessages([
                    'piezas' => 'Una incidencia de «No encontrado en inventario» se registra para una sola pieza con N/S. Escanea su N/S en Piezas afectadas.',
                ]);
            }
            $datos += [
                'numero_serie_id' => $pieza->numero_serie_id,
                'tarima_id' => $pieza->tarima_id,
                'almacen_id' => $pieza->tarima->movimiento?->almacen_origen_id,
                'sku' => $pieza->sku,
                'numero_serie' => $pieza->numero_serie,
            ];
        }

        try {
            return DB::transaction(function () use ($mudanza, $datos, $usuario, $archivos, $partidas) {
                $incidencia = Incidencia::create([
                    'mudanza_id' => $mudanza->id,
                    'folio' => sprintf('INC-%04d', Contador::siguiente(self::claveFolio($mudanza))),
                    'tipo' => $datos['tipo'],
                    'estado' => Incidencia::ABIERTA,
                    'bloquea_traslado' => (bool) ($datos['bloquea_traslado'] ?? true),
                    'numero_serie_id' => $datos['numero_serie_id'] ?? null,
                    'tarima_id' => $datos['tarima_id'] ?? null,
                    'almacen_id' => $datos['almacen_id'] ?? null,
                    'sku' => $datos['sku'] ?? null,
                    'numero_serie' => $datos['numero_serie'] ?? null,
                    'titulo' => $datos['titulo'],
                    'descripcion' => $datos['descripcion'] ?? null,
                    'creada_by' => $usuario->id,
                ]);

                $this->evento($incidencia, IncidenciaEvento::CREADA, $incidencia->bloquea_traslado ? 'Bloquea el traslado de las piezas afectadas.' : 'No bloquea el traslado.', $usuario);
                $this->agregarPartidas($incidencia, $partidas, $usuario);
                $this->adjuntar($incidencia, $archivos, $usuario);

                $this->bitacora->registrar('incidencia.creada', $usuario, $incidencia, [
                    'folio' => $incidencia->folio, 'tipo' => $incidencia->tipo, 'numero_serie' => $incidencia->numero_serie,
                    'piezas' => $partidas->pluck('consecutivo')->all(),
                ], mudanza: $mudanza);

                return $incidencia;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'piezas' => "La N/S {$datos['numero_serie']} ya tiene una incidencia abierta de «No encontrado en inventario».",
            ]);
        }
    }


    public function crearNoEncontrada(NumeroSerie $serie, Tarima $tarima, User $usuario, ?string $pista = null, ?Collection $partidas = null): Incidencia {
        $existente = Incidencia::pendientesDeRevision()->where('numero_serie_id', $serie->id)->first();
        if ($existente) {
            return $existente;
        }

        $serie->loadMissing('registro');
        $almacen = $tarima->movimiento?->origen;

        return $this->crear($tarima->mudanza, [
            'tipo' => Incidencia::NO_EN_INVENTARIO,
            'titulo' => "N/S {$serie->numero_serie} no encontrada en inventario ({$tarima->codigo})",
            'descripcion' => "Se capturó la N/S {$serie->numero_serie} para el SKU {$serie->registro->sku} y no coincide con ninguno de los tres números de serie del inventario"
                .($almacen ? " del {$almacen->etiqueta}" : '').'. Se registró automáticamente como pendiente de revisión.'
                .($pista ? " {$pista}" : ''),
            'bloquea_traslado' => false,
            'numero_serie_id' => $serie->id,
            'tarima_id' => $tarima->id,
            'almacen_id' => $almacen?->id,
            'sku' => $serie->registro->sku,
            'numero_serie' => $serie->numero_serie,
        ], $usuario, partidas: $partidas ?? collect());
    }


    public function vincularPiezasGuardadas(Tarima $tarima, User $usuario): void {
        $partidas = Partida::where('tarima_id', $tarima->id)->whereNotNull('numero_serie_id')->get()->keyBy('numero_serie_id');
        if ($partidas->isEmpty()) {
            return;
        }

        Incidencia::whereIn('numero_serie_id', $partidas->keys())->get()
            ->each(fn (Incidencia $i) => $this->agregarPartidas($i, collect([$partidas[$i->numero_serie_id]]), $usuario));
    }


    public function vincular(Incidencia $incidencia, string $piezas, User $usuario): int {
        $partidas = BuscadorPiezas::resolver($incidencia->mudanza, $piezas);

        return DB::transaction(fn () => $this->agregarPartidas($incidencia, $partidas, $usuario));
    }

    /** @param list<UploadedFile> $archivos */
    public function comentar(Incidencia $incidencia, ?string $texto, User $usuario, array $archivos = []): void {
        if (blank($texto) && $archivos === []) {
            throw ValidationException::withMessages(['comentario' => 'Escribe un comentario o adjunta un archivo.']);
        }

        DB::transaction(function () use ($incidencia, $texto, $usuario, $archivos) {
            if (filled($texto)) {
                $this->evento($incidencia, IncidenciaEvento::COMENTARIO, trim($texto), $usuario);
            }
            $this->adjuntar($incidencia, $archivos, $usuario);
            $incidencia->touch();
        });
    }

    public function asignar(Incidencia $incidencia, ?User $responsable, User $usuario): void {
        DB::transaction(function () use ($incidencia, $responsable, $usuario) {
            $incidencia->update(['responsable_id' => $responsable?->id]);
            $this->evento($incidencia, IncidenciaEvento::ASIGNACION, $responsable ? "Responsable: {$responsable->name}" : 'Sin responsable', $usuario);
        });
    }


    public function reabrir(Incidencia $incidencia, User $usuario, ?string $motivo): void {
        if ($incidencia->estaVigente()) {
            throw ValidationException::withMessages(['nota' => 'La incidencia ya está abierta.']);
        }
        if (mb_strlen(trim((string) $motivo)) < 5) {
            throw ValidationException::withMessages(['nota' => 'Para reabrir escribe el motivo (al menos 5 caracteres).']);
        }

        try {
            DB::transaction(function () use ($incidencia, $usuario, $motivo) {
                $incidencia->update(['estado' => Incidencia::ABIERTA, 'tipo_resolucion' => null, 'resolucion' => null, 'resuelta_at' => null, 'resuelta_by' => null]);
                $this->evento($incidencia, IncidenciaEvento::ESTADO, 'Reabierta: '.trim((string) $motivo), $usuario);
                $this->bitacora->registrar('incidencia.reabierta', $usuario, $incidencia, ['folio' => $incidencia->folio], $motivo, $incidencia->mudanza);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nota' => "La N/S {$incidencia->numero_serie} ya tiene otra incidencia abierta de «No encontrado en inventario»."]);
        }
    }

    /** @param list<UploadedFile> $archivos */
    public function resolver(Incidencia $incidencia, string $tipoResolucion, string $resolucion, User $usuario, array $archivos = []): void {
        if (! $incidencia->estaVigente()) {
            throw ValidationException::withMessages(['resolucion' => 'La incidencia ya está resuelta.']);
        }

        
        if (in_array($tipoResolucion, Incidencia::RESOLUCIONES_DEL_CLIENTE, true) && $archivos === [] && ! $incidencia->evidencias()->exists()) {
            throw ValidationException::withMessages([
                'archivos' => 'Esta resolución es una decisión del cliente: adjunta su respaldo (correo, documento, foto).',
            ]);
        }

        DB::transaction(function () use ($incidencia, $tipoResolucion, $resolucion, $usuario, $archivos) {
            $this->adjuntar($incidencia, $archivos, $usuario);

            $incidencia->update([
                'estado' => Incidencia::RESUELTA,
                'tipo_resolucion' => $tipoResolucion,
                'resolucion' => $resolucion,
                'resuelta_at' => now(),
                'resuelta_by' => $usuario->id,
            ]);
            $this->evento($incidencia, IncidenciaEvento::ESTADO, 'Resuelta: '.Incidencia::RESOLUCIONES[$tipoResolucion].'. '.$resolucion, $usuario);
            $this->bitacora->registrar('incidencia.resuelta', $usuario, $incidencia, [
                'folio' => $incidencia->folio, 'tipo_resolucion' => $tipoResolucion,
            ], $resolucion, $incidencia->mudanza);
        });
    }

    /** @param list<UploadedFile> $archivos */
    private function adjuntar(Incidencia $incidencia, array $archivos, User $usuario): void {
        foreach ($archivos as $archivo) {
            $evidencia = $this->documentos->adjuntar($archivo, $incidencia, $incidencia->mudanza, $usuario);
            $this->evento($incidencia, IncidenciaEvento::EVIDENCIA, $evidencia->nombre_original, $usuario);
        }
    }

    private function agregarPartidas(Incidencia $incidencia, Collection $partidas, User $usuario): int {
        $nuevas = $partidas->reject(fn (Partida $p) => $incidencia->partidas()->whereKey($p->id)->exists());
        if ($nuevas->isEmpty()) {
            return 0;
        }
        $incidencia->partidas()->attach($nuevas->pluck('id')->all());
        $this->evento($incidencia, IncidenciaEvento::PARTIDAS, 'Piezas: '.$nuevas->map(fn (Partida $p) => "#{$p->consecutivo} {$p->ns_mostrada}")->join(', '), $usuario);

        return $nuevas->count();
    }

    private function evento(Incidencia $incidencia, string $tipo, ?string $detalle, User $usuario): void {
        $incidencia->eventos()->create(['tipo' => $tipo, 'detalle' => $detalle, 'user_id' => $usuario->id]);
    }
}
