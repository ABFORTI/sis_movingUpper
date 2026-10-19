<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Embarque extends Model {
    public const EN_CARGA = 'en_carga';
    public const EN_TRANSITO = 'en_transito';
    public const RECIBIDO = 'recibido';

    public const ESTADOS = [
        self::EN_CARGA => 'En carga',
        self::EN_TRANSITO => 'En tránsito',
        self::RECIBIDO => 'Recibido',
    ];

    public const ABIERTOS = [self::EN_CARGA, self::EN_TRANSITO];

    protected $fillable = [
        'mudanza_id',
        'mudanza_movimiento_id',
        'folio',
        'placas',
        'remolque',
        'chofer',
        'sello',
        'estado',
        'notas',
        'creado_by',
        'salida_at',
        'salida_by',
        'recibido_at',
        'recibido_by',
    ];

    protected function casts(): array {
        return [
            'salida_at' => 'datetime',
            'recibido_at' => 'datetime',
        ];
    }

    public function mudanza(): BelongsTo {
        return $this->belongsTo(Mudanza::class);
    }

    /** Traslado (almacén origen → destino) que hace este tráiler. */
    public function movimiento(): BelongsTo {
        return $this->belongsTo(MudanzaMovimiento::class, 'mudanza_movimiento_id');
    }

    public function cargas(): HasMany {
        return $this->hasMany(EmbarquePartida::class);
    }

    public function creadoPor(): BelongsTo {
        return $this->belongsTo(User::class, 'creado_by');
    }

    public function salidaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'salida_by');
    }

    public function recibidoPor(): BelongsTo {
        return $this->belongsTo(User::class, 'recibido_by');
    }

    public function scopeAbiertos(Builder $query): Builder {
        return $query->whereIn('estado', self::ABIERTOS);
    }

    public function enCarga(): bool {
        return $this->estado === self::EN_CARGA;
    }

    public function enTransito(): bool {
        return $this->estado === self::EN_TRANSITO;
    }

    public function getEstadoNombreAttribute(): string {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }
}
