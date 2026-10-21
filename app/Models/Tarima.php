<?php

namespace App\Models;

use App\Enums\TipoRegistro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tarima extends Model {
    use HasUuids;

    protected $fillable = [
        'mudanza_id',
        'mudanza_movimiento_id',
        'numero',
        'created_by',
    ];

    protected function casts(): array {
        return [
            'impresa_at' => 'datetime',
            'guardada_at' => 'datetime',
            'numero' => 'integer',
            'consecutivo_inicial' => 'integer',
            'anulada_at' => 'datetime',
            'reimpresion_pendiente_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array {
        return ['uuid'];
    }

    public function getRouteKeyName(): string {
        return 'uuid';
    }

    public function mudanza(): BelongsTo {
        return $this->belongsTo(Mudanza::class);
    }

    /** Traslado (almacén origen → destino) al que pertenece. Las históricas pueden no tenerlo. */
    public function movimiento(): BelongsTo {
        return $this->belongsTo(MudanzaMovimiento::class, 'mudanza_movimiento_id');
    }

    /** Almacén cuyo inventario valida las piezas de esta tarima. */
    public function almacenOrigen(): ?Almacen {
        return $this->movimiento?->origen;
    }

    /** Piezas guardadas que hoy están físicamente en esta tarima, por consecutivo. */
    public function partidas(): HasMany {
        return $this->hasMany(Partida::class)->where('estado', Partida::EN_TARIMA)->orderBy('consecutivo');
    }

    public function registros(): HasMany {
        return $this->hasMany(Registro::class)->orderBy('id');
    }

    public function creador(): BelongsTo {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function guardadaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'guardada_by');
    }

    public function anuladaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'anulada_by');
    }

    /** Tarimas en captura (la vista de escaneo). Las anuladas ya no se trabajan. */
    public function scopeTemporales(Builder $query): Builder {
        return $query->whereNull('guardada_at')->whereNull('anulada_at');
    }

    /** Tarimas no anuladas: las únicas que cuentan como inventario entregable. */
    public function scopeVigentes(Builder $query): Builder {
        return $query->whereNull('anulada_at');
    }

    /** Lo que entra al Excel de trabajo: embarques vigentes que ya tienen piezas numeradas. */
    public function scopeExportables(Builder $query): Builder {
        return $query->vigentes()->whereHas('partidas');
    }

    /** Tarimas que ya pasaron al historial. */
    public function scopeGuardadas(Builder $query): Builder {
        return $query->whereNotNull('guardada_at');
    }

    /**
     * Misma regla que TarimaPolicy::trabajar, pero como consulta: admin y auditor
     * trabajan todas; el operador solo las que creó. Ver, todos ven todas.
     */
    public function scopeTrabajablesPor(Builder $query, User $usuario): Builder {
        if ($usuario->puedeTrabajarTodasLasTarimas()) {
            return $query;
        }

        return $query->where('created_by', $usuario->id);
    }

    /**
     * Filtros del historial (embarque, descripción, N/S). Filtran embarques
     * completos; los consecutivos de sus piezas no cambian.
     *
     * @param  array{tarima?: ?string, descripcion?: ?string, numero_serie?: ?string}  $filtros
     */
    public function scopeFiltrosHistorial(Builder $query, array $filtros): Builder {
        $like = fn (string $valor) => '%'.addcslashes($valor, '%_\\').'%';

        return $query
            ->when($filtros['tarima'] ?? null, function (Builder $q, string $valor) {
                // "E-004", "Embarque 4", "004" o "4" (y el "T-004" de las etiquetas viejas); si
                // escriben el número completo ("MUD-0001 · E-004") se toma lo que sigue a la última E o T.
                $numero = preg_replace('/\D/', '', preg_replace('/^.*[ET]/i', '', $valor));
                // "E-011" también trae sus adicionales ("E-011_adicional").
                $numero = $numero === '' ? 0 : (int) $numero;
                $q->where(fn (Builder $q) => $q->where('numero', $numero)
                    ->orWhereIn('adicional_de_id', self::where('numero', $numero)->select('id')));
            })
            ->when($filtros['descripcion'] ?? null, fn (Builder $q, string $valor) => $q->whereHas(
                'registros',
                fn (Builder $r) => $r->where('descripcion', 'like', $like($valor))
            ))
            ->when($filtros['numero_serie'] ?? null, fn (Builder $q, string $valor) => $q->whereHas(
                'registros.numerosSerie',
                fn (Builder $n) => $n->where('numero_serie', 'like', $like(trim($valor)))
            ));
    }

    public function estaImpresa(): bool {
        return $this->impresa_at !== null;
    }

    public function estaGuardada(): bool {
        return $this->guardada_at !== null;
    }

    public function estaAnulada(): bool {
        return $this->anulada_at !== null;
    }

    /**
     * Códigos de los embarques a los que se movieron piezas que salieron de
     * éste y siguen allá. Un embarque que se quedó sin piezas porque todas se
     * trasladaron no está "vacío": sus piezas están en otro.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function trasladadaA(): \Illuminate\Support\Collection {
        return $this->traslados()->with('destino')->get()->pluck('destino.codigo')->filter()->unique()->sort()->values();
    }

    /** Movimientos de piezas que salieron de este embarque y siguen en el embarque al que llegaron. */
    public function traslados(): Builder {
        return MovimientoPartida::query()
            ->where('tipo', MovimientoPartida::CAMBIO_TARIMA)
            ->where('tarima_origen_id', $this->id)
            ->whereHas('partida', fn (Builder $q) => $q->where('estado', Partida::EN_TARIMA)->whereColumn('partidas.tarima_id', 'movimientos_partida.tarima_destino_id'));
    }

    /** Embarque al que se movieron todas sus piezas: éste se llama como aquél con "_adicional". */
    public function adicionalDe(): BelongsTo {
        return $this->belongsTo(self::class, 'adicional_de_id');
    }

    public function esAdicional(): bool {
        return $this->adicional_de_id !== null;
    }

    /** Ya salió de la impresora la etiqueta de al menos una de sus piezas. */
    public function tieneEtiquetasImpresas(): bool {
        return NumeroSerie::whereHas('registro', fn (Builder $q) => $q->where('tarima_id', $this->id))
            ->whereNotNull('etiqueta_impresa_at')->exists();
    }

    /** Se movieron o retiraron piezas después de guardarla: la etiqueta pegada ya no coincide. */
    public function requiereReimpresion(): bool {
        return $this->reimpresion_pendiente_at !== null;
    }

    /**
     * "E-004": número de embarque dentro de su mudanza. En pantalla y en las
     * etiquetas este modelo es el "embarque" (las piezas que van juntas en la
     * caja de un tráiler); la tabla y las clases conservan el nombre "tarima".
     */
    public function getCodigoAttribute(): string {
        if (! $this->esAdicional() || ! $this->adicionalDe) {
            return $this->codigo_original;
        }

        // Varios adicionales del mismo embarque: "E-011_adicional", "E-011_adicional_2"…
        $orden = self::where('adicional_de_id', $this->adicional_de_id)->where('numero', '<', $this->numero)->count() + 1;

        return $this->adicionalDe->codigo_original.'_adicional'.($orden > 1 ? "_{$orden}" : '');
    }

    /** "E-012": el número con que se creó, aunque hoy se llame "E-011_adicional". */
    public function getCodigoOriginalAttribute(): string {
        return 'E-'.str_pad((string) $this->numero, 3, '0', STR_PAD_LEFT);
    }

    /** "Embarque E-004", para mensajes y pantallas dentro de la mudanza. */
    public function getNombreAttribute(): string {
        return "Embarque {$this->codigo}";
    }

    /** "MUD-0001 · E-004": número de embarque completo, el que va en las etiquetas y archivos. */
    public function getIdentificadorAttribute(): string {
        return $this->mudanza->folio.' · '.$this->codigo;
    }

    public function piezas(): int {
        return $this->registros->sum(
            fn (Registro $r) => $r->tipo === TipoRegistro::Padre ? $r->numerosSerie->count() : 1
        );
    }

    /**
     * Se puede guardar: tiene piezas y cada pieza con N/S ya tiene su etiqueta
     * impresa (una por pieza). Los registros históricos sin N/S no llevan
     * etiqueta de pieza y no lo impiden.
     */
    public function listaParaGuardar(): bool {
        $this->loadMissing('registros.numerosSerie');

        return $this->piezas() > 0
            && $this->registros->flatMap->numerosSerie->doesntContain(fn (NumeroSerie $ns) => $ns->etiquetaPendiente());
    }

    public function esSoloPadre(): bool {
        return $this->registros->isNotEmpty()
            && $this->registros->every(fn (Registro $r) => $r->tipo === TipoRegistro::Padre);
    }

    public function cargarDetalle(): static {
        return $this->loadMissing(['movimiento.origen', 'movimiento.destino', 'registros.destino', 'registros.numerosSerie.incidenciaPendiente']);
    }
}
