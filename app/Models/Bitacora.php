<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;


class Bitacora extends Model {
    public const UPDATED_AT = null;

    protected $table = 'bitacora';

    protected $fillable = [
        'mudanza_id',
        'user_id',
        'accion',
        'entidad_type',
        'entidad_id',
        'datos',
        'motivo',
        'ip',
        'user_agent',
    ];

    protected function casts(): array {
        return [
            'datos' => 'array',
        ];
    }

    protected static function booted(): void {
        static::updating(fn () => throw new LogicException('La bitácora no se puede modificar.'));
        static::deleting(fn () => throw new LogicException('La bitácora no se puede borrar.'));
    }

    public function usuario(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }
}
