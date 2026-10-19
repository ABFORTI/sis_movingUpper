<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un Excel de inventario cargado para un almacén. Se conserva aunque se
 * reemplace (historial); solo una carga por almacén está vigente.
 */
class InventarioCarga extends Model {
    protected $table = 'inventario_cargas';

    protected $fillable = [
        'almacen_id',
        'archivo_nombre',
        'archivo',
        'sha256',
        'filas_leidas',
        'filas_importadas',
        'filas_omitidas',
        'observaciones',
        'vigente',
        'cargada_by',
        'reemplazada_at',
    ];

    protected function casts(): array {
        return [
            'observaciones' => 'array',
            'vigente' => 'boolean',
            'reemplazada_at' => 'datetime',
        ];
    }

    public function almacen(): BelongsTo {
        return $this->belongsTo(Almacen::class);
    }

    public function items(): HasMany {
        return $this->hasMany(InventarioItem::class);
    }

    public function cargadaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'cargada_by');
    }
}
