<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SKU dado de alta a mano en un almacén, sin números de serie: existe para el
 * escaneo aunque aún no venga en el inventario de Excel. No es un renglón del
 * inventario: sus N/S, al escanearse, no tienen con qué coincidir y quedan
 * pendientes de revisión como cualquier N/S fuera del inventario.
 */
class SkuAlta extends Model {
    protected $table = 'skus_alta';

    protected $fillable = [
        'almacen_id',
        'sku',
        'sku_norm',
        'descripcion',
        'created_by',
    ];

    public function almacen(): BelongsTo {
        return $this->belongsTo(Almacen::class);
    }

    public function creador(): BelongsTo {
        return $this->belongsTo(User::class, 'created_by');
    }
}
