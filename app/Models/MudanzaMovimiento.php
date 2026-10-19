<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un traslado dentro de la mudanza: almacén origen → almacén destino. Una
 * mudanza puede tener varios (58 → 3 y 5 → 7). Las tarimas de un movimiento
 * se validan contra el inventario de su almacén de origen.
 */
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
