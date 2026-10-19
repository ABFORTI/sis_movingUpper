<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pieza en un embarque: quién y cuándo la subió (carga) y quién y cuándo la
 * bajó en el destino (recepción). Carga y recepción viven en la misma fila, así
 * que una pieza tiene un solo registro por traslado. `autorizacion_id` solo
 * existe en cargas anteriores a 2026-10-12 (histórico).
 */
class EmbarquePartida extends Model {
    public $timestamps = false;

    protected $fillable = [
        'embarque_id',
        'partida_id',
        'tarima_id',
        'cargada_at',
        'cargada_by',
        'recibida_at',
        'recibida_by',
    ];

    protected function casts(): array {
        return [
            'cargada_at' => 'datetime',
            'recibida_at' => 'datetime',
        ];
    }

    public function embarque(): BelongsTo {
        return $this->belongsTo(Embarque::class);
    }

    public function partida(): BelongsTo {
        return $this->belongsTo(Partida::class);
    }

    public function tarima(): BelongsTo {
        return $this->belongsTo(Tarima::class);
    }

    public function cargadaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'cargada_by');
    }

    public function recibidaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'recibida_by');
    }
}
