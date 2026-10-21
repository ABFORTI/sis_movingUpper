<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class EntregaPartida extends Model {
    public const NUEVA = 'nueva';
    public const CAMBIO_TARIMA = 'tarima';
    public const IGUAL = 'igual';

    public const NOMBRES = [
        self::NUEVA => 'Nueva',
        self::CAMBIO_TARIMA => 'Cambió de embarque',
        self::IGUAL => '',
    ];

    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;

    protected $table = 'entrega_partidas';

    protected $fillable = [
        'entrega_id',
        'partida_id',
        'consecutivo',
        'tarima_numero',
        'sku',
        'descripcion',
        'numero_serie',
        'categoria',
        'registrado_at',
        'novedad',
    ];

    protected function casts(): array {
        return [
            'consecutivo' => 'integer',
            'tarima_numero' => 'integer',
            'registrado_at' => 'datetime',
        ];
    }

    public function entrega(): BelongsTo {
        return $this->belongsTo(Entrega::class);
    }

    public function partida(): BelongsTo {
        return $this->belongsTo(Partida::class);
    }
}
