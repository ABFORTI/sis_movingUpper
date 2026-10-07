<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una confirmación de impresión de la etiqueta de una tarima. */
class Impresion extends Model {
    public const UPDATED_AT = null;

    protected $table = 'impresiones';

    protected $fillable = [
        'tarima_id',
        'user_id',
        'etiquetas',
        'es_reimpresion',
    ];

    protected function casts(): array {
        return [
            'etiquetas' => 'integer',
            'es_reimpresion' => 'boolean',
        ];
    }

    public function tarima(): BelongsTo {
        return $this->belongsTo(Tarima::class);
    }

    public function usuario(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }
}
