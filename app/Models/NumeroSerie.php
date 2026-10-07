<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class NumeroSerie extends Model {
    
    use SoftDeletes;
    protected $table = 'numeros_serie';

    protected $fillable = [
        'registro_id',
        'numero_serie',
        'user_id',
        'deleted_by',
    ];

    public function registro(): BelongsTo {
        return $this->belongsTo(Registro::class);
    }
}
