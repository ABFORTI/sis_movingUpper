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


    public function movimiento(): BelongsTo {
        return $this->belongsTo(MudanzaMovimiento::class, 'mudanza_movimiento_id');
    }


    public function almacenOrigen(): ?Almacen {
        return $this->movimiento?->origen;
    }


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


    public function scopeTemporales(Builder $query): Builder {
        return $query->whereNull('guardada_at')->whereNull('anulada_at');
    }


    public function scopeVigentes(Builder $query): Builder {
        return $query->whereNull('anulada_at');
    }


    public function scopeExportables(Builder $query): Builder {
        return $query->vigentes()->whereHas('partidas');
    }


    public function scopeGuardadas(Builder $query): Builder {
        return $query->whereNotNull('guardada_at');
    }


    public function scopeTrabajablesPor(Builder $query, User $usuario): Builder {
        if ($usuario->puedeTrabajarTodasLasTarimas()) {
            return $query;
        }

        return $query->where('created_by', $usuario->id);
    }

    /**
     *
     * @param  array{tarima?: ?string, descripcion?: ?string, numero_serie?: ?string}  $filtros
     */
    public function scopeFiltrosHistorial(Builder $query, array $filtros): Builder {
        $like = fn (string $valor) => '%'.addcslashes($valor, '%_\\').'%';

        return $query
            ->when($filtros['tarima'] ?? null, function (Builder $q, string $valor) {
                $numero = preg_replace('/\D/', '', preg_replace('/^.*[ET]/i', '', $valor));
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
     * Códigos de los embarques a los que se movieron piezas que salieron de éste y siguen allá.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function trasladadaA(): \Illuminate\Support\Collection {
        return $this->traslados()->with('destino')->get()->pluck('destino.codigo')->filter()->unique()->sort()->values();
    }

    public function traslados(): Builder {
        return MovimientoPartida::query()
            ->where('tipo', MovimientoPartida::CAMBIO_TARIMA)
            ->where('tarima_origen_id', $this->id)
            ->whereHas('partida', fn (Builder $q) => $q->where('estado', Partida::EN_TARIMA)->whereColumn('partidas.tarima_id', 'movimientos_partida.tarima_destino_id'));
    }


    public function adicionalDe(): BelongsTo {
        return $this->belongsTo(self::class, 'adicional_de_id');
    }

    public function esAdicional(): bool {
        return $this->adicional_de_id !== null;
    }


    public function tieneEtiquetasImpresas(): bool {
        return NumeroSerie::whereHas('registro', fn (Builder $q) => $q->where('tarima_id', $this->id))
            ->whereNotNull('etiqueta_impresa_at')->exists();
    }

    public function requiereReimpresion(): bool {
        return $this->reimpresion_pendiente_at !== null;
    }


    public function getCodigoAttribute(): string {
        if (! $this->esAdicional() || ! $this->adicionalDe) {
            return $this->codigo_original;
        }

        $orden = self::where('adicional_de_id', $this->adicional_de_id)->where('numero', '<', $this->numero)->count() + 1;

        return $this->adicionalDe->codigo_original.'_adicional'.($orden > 1 ? "_{$orden}" : '');
    }

    public function getCodigoOriginalAttribute(): string {
        return 'E-'.str_pad((string) $this->numero, 3, '0', STR_PAD_LEFT);
    }

    public function getNombreAttribute(): string {
        return "Embarque {$this->codigo}";
    }

    public function getIdentificadorAttribute(): string {
        return $this->mudanza->folio.' · '.$this->codigo;
    }

    public function piezas(): int {
        return $this->registros->sum(
            fn (Registro $r) => $r->tipo === TipoRegistro::Padre ? $r->numerosSerie->count() : 1
        );
    }


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
