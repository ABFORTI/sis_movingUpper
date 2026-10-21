<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


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
