<?php

namespace App\Models;

use App\Enums\Demo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


class Partida extends Model {
    public const EN_TARIMA = 'en_tarima';
    public const RETIRADA = 'retirada';
    public const TIPO_NS = 'ns';
    public const TIPO_SKU = 'sku';
    public const TIPO_SIN_NS = 'sin_ns';

    protected $attributes = ['demo' => 'Na'];

    protected $fillable = [
        'mudanza_id',
        'tarima_id',
        'registro_id',
        'numero_serie_id',
        'consecutivo',
        'tipo_identificacion',
        'sku',
        'descripcion',
        'demo',
        'numero_serie',
        'destino_id',
        'registrado_at',
        'estado',
    ];

    protected function casts(): array {
        return [
            'consecutivo' => 'integer',
            'demo' => Demo::class,
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


    public function incidenciaPendiente(): HasOne {
        return $this->hasOne(Incidencia::class, 'numero_serie_id', 'numero_serie_id')->pendientesDeRevision();
    }

    public function pendienteRevision(): bool {
        return $this->numero_serie_id !== null && $this->incidenciaPendiente !== null;
    }


    public function carga(): HasOne {
        return $this->hasOne(EmbarquePartida::class);
    }

    public function scopeEnTarima(Builder $query): Builder {
        return $query->where('estado', self::EN_TARIMA);
    }


    public function scopeBloqueadas(Builder $query): Builder {
        return $query->whereHas('incidencias', fn (Builder $q) => $q->vigentes()->where('bloquea_traslado', true));
    }

    public function estaRetirada(): bool {
        return $this->estado === self::RETIRADA;
    }


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
