<?php

namespace App\Services;

use App\Models\Entrega;
use App\Models\EntregaPartida;
use App\Models\Incidencia;
use App\Models\Mudanza;
use App\Models\Partida;
use App\Models\Tarima;
use App\Models\User;
use App\Services\Exportacion\ExcelExportador;
use App\Support\Contador;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reporte Diario (antes "Entregas de inventario"): el Excel formal para el
 * cliente con el trabajo de un día, es decir, las piezas vigentes cuya fecha de
 * registro (cuando se escaneó su N/S, la columna "Fecha de registro" del Excel)
 * es ese día. Cada reporte lleva versión correlativa de la mudanza, archivo
 * guardado con SHA-256 y una copia de cada pieza tal como se entregó; si se
 * vuelve a generar el mismo día, se compara contra el reporte anterior de ese
 * día. Así se puede responder "¿qué se le entregó al cliente y cuándo?" aunque
 * después se corrijan embarques. Las entregas anteriores (mudanza completa,
 * sin fecha) se conservan tal cual.
 */
class EntregaService {
    public function __construct(
        private readonly ExcelExportador $excel,
        private readonly DocumentoService $documentos,
        private readonly BitacoraService $bitacora,
    ) { }

    public static function claveVersion(Mudanza $mudanza): string {
        return "entregas_mudanza_{$mudanza->id}";
    }

    /** Inicio y fin del día en la zona horaria de la aplicación. */
    public static function dia(CarbonInterface|string $fecha): array {
        $dia = CarbonImmutable::parse($fecha instanceof CarbonInterface ? $fecha->format('Y-m-d') : $fecha, config('app.timezone'))->startOfDay();

        return [$dia, $dia->endOfDay()];
    }

    /**
     * Las piezas trabajadas ese día (las que entrarían al reporte), agrupadas
     * por embarque, para revisarlas antes de generarlo.
     *
     * @return Collection<int, array{codigo: string, piezas: int, skus: int}>
     */
    public function resumenDelDia(Mudanza $mudanza, CarbonInterface|string $fecha): Collection {
        return $this->partidasVigentes($mudanza, $fecha)
            ->groupBy('tarima_id')
            ->map(fn (Collection $p) => [
                'codigo' => $p->first()->tarima->codigo,
                'numero' => $p->first()->tarima->numero,
                'piezas' => $p->count(),
                'skus' => $p->pluck('sku')->unique()->count(),
            ])
            ->sortBy('numero')->values();
    }

    /** Lo que conviene revisar antes de entregar: piezas que no saldrán o etiquetas desactualizadas. */
    public function pendientes(Mudanza $mudanza, CarbonInterface|string|null $fecha = null): array {
        $enCaptura = Tarima::temporales()->where('mudanza_id', $mudanza->id)
            ->when($fecha, function ($q) use ($fecha) {
                // Solo las que tienen piezas escaneadas ese día: esas son las que faltarían en el reporte.
                $q->whereHas('registros.numerosSerie', fn ($n) => $n->whereBetween('numeros_serie.created_at', self::dia($fecha)));
            }, fn ($q) => $q->whereHas('registros'))
            ->orderBy('numero')->get();

        return [
            'en_captura' => $enCaptura->pluck('codigo')->all(),
            'reimprimir' => Tarima::vigentes()->where('mudanza_id', $mudanza->id)->whereNotNull('reimpresion_pendiente_at')->orderBy('numero')->get()->pluck('codigo')->all(),
            'incidencias' => Incidencia::vigentes()->where('mudanza_id', $mudanza->id)->count(),
        ];
    }

    public function generar(Mudanza $mudanza, User $usuario, ?string $notas = null, CarbonInterface|string|null $fecha = null): Entrega {
        if (in_array($mudanza->estado, [Mudanza::BORRADOR, Mudanza::CANCELADA], true)) {
            throw ValidationException::withMessages(['entrega' => "La {$mudanza->folio} está {$mudanza->estado_nombre}: no se generan reportes."]);
        }
        [$dia] = self::dia($fecha ?? now());

        $guardado = null;

        try {
            return DB::transaction(function () use ($mudanza, $usuario, $notas, $dia, &$guardado) {
                // Un reporte a la vez por mudanza: la versión y la comparación con el anterior no se cruzan.
                Mudanza::whereKey($mudanza->id)->lockForUpdate()->first();

                $partidas = $this->partidasVigentes($mudanza, $dia);
                if ($partidas->isEmpty()) {
                    throw ValidationException::withMessages(['entrega' => "No hay piezas guardadas registradas el {$dia->format('d/m/Y')}."]);
                }

                // Se compara contra el reporte anterior del mismo día.
                $anterior = Entrega::where('mudanza_id', $mudanza->id)->whereDate('fecha', $dia->format('Y-m-d'))->latest('version')->first();
                $previas = $anterior ? $anterior->filas()->get()->keyBy('partida_id')->toBase() : collect();

                $filas = $partidas->map(fn (Partida $p) => $this->copia($p, $anterior ? $previas->get($p->id) : null, $anterior !== null));
                $salieron = $previas->except($partidas->modelKeys())->values();
                $version = Contador::siguiente(self::claveVersion($mudanza));

                $resumen = [
                    'total_partidas' => $filas->count(),
                    'nuevas' => $filas->where('novedad', EntregaPartida::NUEVA)->count(),
                    'cambiadas' => $filas->where('novedad', EntregaPartida::CAMBIO_TARIMA)->count(),
                    'salieron' => $salieron->count(),
                ];

                $contenido = $this->excel->archivoEntrega(
                    $this->control($mudanza, $version, $usuario, $notas, $resumen, $anterior, $dia),
                    $filas->map(fn (array $f) => $this->filaExcel($f, $partidas->firstWhere('id', $f['partida_id'])))->all(),
                    $this->filasSalieron($salieron),
                );
                $guardado = $this->documentos->guardarContenido("entregas/{$mudanza->folio}", 'xlsx', $contenido);

                $entrega = Entrega::create($resumen + [
                    'mudanza_id' => $mudanza->id,
                    'version' => $version,
                    'fecha' => $dia->format('Y-m-d'),
                    'archivo' => $guardado['archivo'],
                    'sha256' => $guardado['sha256'],
                    'tamano' => $guardado['tamano'],
                    'notas' => $notas,
                    'generada_by' => $usuario->id,
                ]);

                foreach ($filas->chunk(500) as $lote) {
                    EntregaPartida::insert($lote->map(fn (array $f) => ['entrega_id' => $entrega->id] + $f)->values()->all());
                }

                $this->bitacora->registrar('entrega.generada', $usuario, $entrega, $resumen + [
                    'version' => $version,
                    'fecha' => $dia->format('Y-m-d'),
                    'sha256' => $guardado['sha256'],
                ], mudanza: $mudanza);

                return $entrega;
            });
        } catch (Throwable $e) {
            if ($guardado) {
                $this->documentos->disco()->delete($guardado['archivo']);
            }
            throw $e;
        }
    }

    /** @return Collection<int, Partida> piezas registradas ese día en tarimas no anuladas, por consecutivo */
    private function partidasVigentes(Mudanza $mudanza, CarbonInterface|string $fecha): Collection {
        return Partida::enTarima()
            ->where('mudanza_id', $mudanza->id)
            ->whereBetween('registrado_at', self::dia($fecha))
            ->whereHas('tarima', fn ($q) => $q->vigentes())
            // La carga más reciente: la del tráiler en que va (o fue) la pieza.
            ->with(['tarima', 'destino', 'incidenciaPendiente', 'incidencias' => fn ($q) => $q->vigentes(), 'carga' => fn ($q) => $q->latest('id')->with('embarque')])
            ->orderBy('consecutivo')
            ->get();
    }

    private function copia(Partida $p, ?EntregaPartida $previa, bool $hayAnterior): array {
        return [
            'partida_id' => $p->id,
            'consecutivo' => $p->consecutivo,
            'tarima_numero' => $p->tarima->numero,
            'sku' => $p->sku,
            'descripcion' => $p->descripcion,
            'numero_serie' => $p->ns_mostrada,
            // Histórico: la categoría ya no se captura ni sale en el Excel.
            'categoria' => $p->destino?->codigo,
            'registrado_at' => $p->registrado_at,
            'novedad' => match (true) {
                ! $hayAnterior => EntregaPartida::IGUAL,
                $previa === null => EntregaPartida::NUEVA,
                $previa->tarima_numero !== $p->tarima->numero => EntregaPartida::CAMBIO_TARIMA,
                default => EntregaPartida::IGUAL,
            },
        ];
    }

    private function filaExcel(array $f, Partida $p): array {
        return [
            $f['consecutivo'],
            $f['sku'],
            $f['descripcion'] ?: '(sin descripción)',
            ExcelExportador::CANTIDAD,
            ExcelExportador::UNIDAD,
            ExcelExportador::DANO,
            $f['numero_serie'],
            $p->carga?->embarque?->folio,
            $f['registrado_at']?->format('Y-m-d H:i:s'),
            EntregaPartida::NOMBRES[$f['novedad']],
            $p->incidencias->pluck('folio')->join(', '),
            ExcelExportador::PENDIENTE => $p->pendienteRevision(),
        ];
    }

    /** @param Collection<int, EntregaPartida> $salieron */
    private function filasSalieron(Collection $salieron): array {
        $partidas = Partida::with('tarima')->findMany($salieron->pluck('partida_id'))->keyBy('id');

        return $salieron->sortBy('consecutivo')->map(function (EntregaPartida $f) use ($partidas) {
            $p = $partidas->get($f->partida_id);
            $motivo = match (true) {
                $p?->estaRetirada() => 'Retirada',
                (bool) $p?->tarima?->estaAnulada() => "Embarque {$p->tarima->codigo} anulado",
                default => 'Ya no está en un embarque entregable',
            };

            return [$f->consecutivo, $f->sku, $f->numero_serie, 'E-'.str_pad((string) $f->tarima_numero, 3, '0', STR_PAD_LEFT), $motivo];
        })->values()->all();
    }

    private function control(Mudanza $mudanza, int $version, User $usuario, ?string $notas, array $resumen, ?Entrega $anterior, CarbonInterface $dia): array {
        return [
            ['Mudanza', $mudanza->folio],
            ['Cliente', (string) $mudanza->cliente],
            ['Movimientos (origen → destino)', $mudanza->rutas],
            ['Día del reporte', $dia->format('Y-m-d')],
            ['Incluye', 'Piezas guardadas cuya fecha de registro es ese día'],
            ['Versión del reporte', $version],
            ['Compara contra', $anterior ? "Versión {$anterior->version} ({$anterior->created_at->format('Y-m-d H:i')})" : 'Primer reporte del día'],
            ['Generada', now()->format('Y-m-d H:i:s')],
            ['Generada por', $usuario->name],
            ['Piezas en esta versión', $resumen['total_partidas']],
            ['Nuevas', $resumen['nuevas']],
            ['Cambiaron de embarque', $resumen['cambiadas']],
            ['Salieron (hoja "Salieron")', $resumen['salieron']],
            ['Notas', (string) $notas],
            ['Integridad', 'El sistema guarda el SHA-256 de este archivo; cualquier cambio lo hace distinto del entregado.'],
        ];
    }
}
