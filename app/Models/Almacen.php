<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


class Almacen extends Model {
    protected $table = 'almacenes';

    protected $fillable = [
        'codigo',
        'nombre',
    ];

    public function cargas(): HasMany {
        return $this->hasMany(InventarioCarga::class)->orderByDesc('id');
    }


    public function inventarioVigente(): HasOne {
        return $this->hasOne(InventarioCarga::class)->where('vigente', true);
    }


    public function skusAlta(): HasMany {
        return $this->hasMany(SkuAlta::class)->orderBy('sku_norm');
    }


    public function getEtiquetaAttribute(): string {
        return 'Almacén '.$this->codigo.($this->nombre ? " · {$this->nombre}" : '');
    }


    public static function normalizarCodigo(string $codigo): string {
        return trim((string) preg_replace('/\s+/u', ' ', $codigo));
    }
}
