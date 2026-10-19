<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Una entrada del historial de una incidencia. Solo se inserta. */
class IncidenciaEvento extends Model {
    public const UPDATED_AT = null;

    public const CREADA = 'creada';
    public const COMENTARIO = 'comentario';
    public const ESTADO = 'estado';
    public const ASIGNACION = 'asignacion';
    public const EVIDENCIA = 'evidencia';
    public const PARTIDAS = 'partidas';

    protected $table = 'incidencia_eventos';

    protected $fillable = ['incidencia_id', 'tipo', 'detalle', 'user_id'];

    protected static function booted(): void {
        static::updating(fn () => throw new LogicException('El historial de la incidencia no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial de la incidencia no se borra.'));
    }

    public function incidencia(): BelongsTo {
        return $this->belongsTo(Incidencia::class);
    }

    public function usuario(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }
}
