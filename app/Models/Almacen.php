<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Almacén del cliente (p. ej. "58"). Cada uno conserva su propio inventario. */
class Almacen extends Model {
    protected $table = 'almacenes';

    protected $fillable = [
        'codigo',
        'nombre',
    ];

    public function cargas(): HasMany {
        return $this->hasMany(InventarioCarga::class)->orderByDesc('id');
    }

    /** La carga de inventario contra la que se valida hoy el escaneo. */
    public function inventarioVigente(): HasOne {
        return $this->hasOne(InventarioCarga::class)->where('vigente', true);
    }

    /** SKU dados de alta a mano (sin N/S) porque aún no vienen en su inventario. */
    public function skusAlta(): HasMany {
        return $this->hasMany(SkuAlta::class)->orderBy('sku_norm');
    }

    /** "Almacén 58" o "Almacén 58 · Bodega norte". */
    public function getEtiquetaAttribute(): string {
        return 'Almacén '.$this->codigo.($this->nombre ? " · {$this->nombre}" : '');
    }

    /** El código tal como lo escriben: sin espacios sobrantes. */
    public static function normalizarCodigo(string $codigo): string {
        return trim((string) preg_replace('/\s+/u', ' ', $codigo));
    }
}
