<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Un renglón del inventario de un almacén: un producto con hasta tres números de serie. */
class InventarioItem extends Model {
    public const NS_FABRICANTE = 'ns_fabricante';
    public const NUMERO_SERIE = 'numero_serie';
    public const NS_LOTE = 'ns_lote';

    /** Los tres campos de serie, en el orden en que se buscan. */
    public const CAMPOS_SERIE = [
        self::NS_FABRICANTE => 'Número de serie del fabricante',
        self::NUMERO_SERIE => 'Número de serie',
        self::NS_LOTE => 'Número de serie de lote',
    ];

    public $timestamps = false;

    protected $table = 'inventario_items';

    protected $fillable = [
        'inventario_carga_id',
        'almacen_id',
        'fila',
        'sku',
        'sku_norm',
        'modelo',
        'descripcion',
        'posicion',
        'ns_fabricante',
        'ns_fabricante_norm',
        'numero_serie',
        'numero_serie_norm',
        'ns_lote',
        'ns_lote_norm',
    ];

    public function carga(): BelongsTo {
        return $this->belongsTo(InventarioCarga::class, 'inventario_carga_id');
    }

    public function almacen(): BelongsTo {
        return $this->belongsTo(Almacen::class);
    }

    /** La N/S viva que ya registró este producto, si la hay. */
    public function registro(): HasOne {
        return $this->hasOne(NumeroSerie::class);
    }
}
