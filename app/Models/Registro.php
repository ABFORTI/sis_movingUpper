<?php

namespace App\Models;

use App\Enums\Demo;
use App\Enums\TipoRegistro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Registro extends Model {
    use SoftDeletes;

    protected $attributes = ['demo' => 'Na'];

    protected $fillable = [
        'tarima_id',
        'tipo',
        'sku',
        'sku_original',
        'descripcion',
        'demo',
        'destino_id',
        'user_id',
        'deleted_by',
    ];

    protected function casts(): array {
        return [
            'tipo' => TipoRegistro::class,
            'demo' => Demo::class,
        ];
    }

    public function tarima(): BelongsTo {
        return $this->belongsTo(Tarima::class);
    }

    public function destino(): BelongsTo {
        return $this->belongsTo(Destino::class);
    }

    public function usuario(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function numerosSerie(): HasMany {
        return $this->hasMany(NumeroSerie::class)->orderBy('id');
    }

    public function fueConvertido(): bool {
        return $this->sku_original !== null;
    }

    public function getDescripcionMostradaAttribute(): string {
        return $this->descripcion ?: '(sin descripción)';
    }
}
