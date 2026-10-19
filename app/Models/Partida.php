<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una pieza ya guardada: una fila del Excel. Su consecutivo es fijo para
 * siempre; si la pieza cambia de tarima o se retira, queda en movimientos.
 */
class Partida extends Model {
    public const EN_TARIMA = 'en_tarima';
    public const RETIRADA = 'retirada';

    /**
     * Con N/S · SKU suelto · SKU padre sin N/S capturadas. Las dos últimas solo
     * existen en registros históricos: hoy toda pieza se registra con SKU + N/S.
     */
    public const TIPO_NS = 'ns';
    public const TIPO_SKU = 'sku';
    public const TIPO_SIN_NS = 'sin_ns';

    protected $fillable = [
        'mudanza_id',
        'tarima_id',
        'registro_id',
        'numero_serie_id',
        'consecutivo',
        'tipo_identificacion',
        'sku',
        'descripcion',
        'numero_serie',
        'destino_id',
        'registrado_at',
        'estado',
    ];

    protected function casts(): array {
        return [
            'consecutivo' => 'integer',
            'registrado_at' => 'datetime',
            'retirada_at' => 'datetime',
        ];
    }

    public function tarima(): BelongsTo {
        return $this->belongsTo(Tarima::class);
    }

    public function mudanza(): BelongsTo {
        return $this->belongsTo(Mudanza::class);
    }

    public function registro(): BelongsTo {
        return $this->belongsTo(Registro::class)->withTrashed();
    }

    public function numeroSerie(): BelongsTo {
        return $this->belongsTo(NumeroSerie::class)->withTrashed();
    }

    public function destino(): BelongsTo {
        return $this->belongsTo(Destino::class);
    }

    public function movimientos(): HasMany {
        return $this->hasMany(MovimientoPartida::class)->orderBy('id');
    }

    public function incidencias(): BelongsToMany {
        return $this->belongsToMany(Incidencia::class, 'incidencia_partida');
    }

    /** Incidencia abierta de "no encontrado en inventario" de su N/S: pieza pendiente de revisión. */
    public function incidenciaPendiente(): HasOne {
        return $this->hasOne(Incidencia::class, 'numero_serie_id', 'numero_serie_id')->pendientesDeRevision();
    }

    public function pendienteRevision(): bool {
        return $this->numero_serie_id !== null && $this->incidenciaPendiente !== null;
    }

    /** Su lugar en un embarque, si ya se cargó. */
    public function carga(): HasOne {
        return $this->hasOne(EmbarquePartida::class);
    }

    public function scopeEnTarima(Builder $query): Builder {
        return $query->where('estado', self::EN_TARIMA);
    }

    /** Piezas con una incidencia vigente que bloquea su traslado: no se pueden cargar. */
    public function scopeBloqueadas(Builder $query): Builder {
        return $query->whereHas('incidencias', fn (Builder $q) => $q->vigentes()->where('bloquea_traslado', true));
    }

    public function estaRetirada(): bool {
        return $this->estado === self::RETIRADA;
    }

    /** Lo que se muestra en la columna N/S: la serie, "—" para SKU suelto o "(sin N/S)". */
    public function getNsMostradaAttribute(): string {
        return match ($this->tipo_identificacion) {
            self::TIPO_SKU => '—',
            self::TIPO_SIN_NS => '(sin N/S)',
            default => (string) $this->numero_serie,
        };
    }

    public function getDescripcionMostradaAttribute(): string {
        return $this->descripcion ?: '(sin descripción)';
    }
}
