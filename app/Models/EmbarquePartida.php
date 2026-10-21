<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


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
