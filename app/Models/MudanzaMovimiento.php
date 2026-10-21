<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class MudanzaMovimiento extends Model {
    protected $table = 'mudanza_movimientos';

    protected $fillable = [
        'mudanza_id',
        'almacen_origen_id',
        'almacen_destino_id',
    ];

    public function mudanza(): BelongsTo {
        return $this->belongsTo(Mudanza::class);
    }

    public function origen(): BelongsTo {
        return $this->belongsTo(Almacen::class, 'almacen_origen_id');
    }

    public function destino(): BelongsTo {
        return $this->belongsTo(Almacen::class, 'almacen_destino_id');
    }

    public function tarimas(): HasMany {
        return $this->hasMany(Tarima::class);
    }

    /** "58 → 3". */
    public function getNombreAttribute(): string {
        return "{$this->origen->codigo} → {$this->destino->codigo}";
    }
}
