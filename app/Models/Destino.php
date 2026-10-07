<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Destino extends Model {
    protected $fillable = [
        'codigo',
        'nombre',
        'is_active',
    ];

    protected function casts(): array {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $query): Builder {
        return $query->where('is_active', true)->orderBy('codigo');
    }
}
