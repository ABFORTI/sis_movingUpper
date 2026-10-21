<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;


class MovimientoPartida extends Model {
    public const UPDATED_AT = null;

    public const ALTA = 'alta';
    public const CAMBIO_TARIMA = 'cambio_tarima';
    public const RETIRO = 'retiro';
    public const NS_AGREGADA = 'ns_agregada';
    public const NS_EDITADA = 'ns_editada';
    public const NS_ELIMINADA = 'ns_eliminada';

    public const NOMBRES = [
        self::ALTA => 'Alta',
        self::CAMBIO_TARIMA => 'Cambio de embarque',
        self::RETIRO => 'Retiro',
        self::NS_AGREGADA => 'N/S agregada',
        self::NS_EDITADA => 'N/S editada',
        self::NS_ELIMINADA => 'N/S eliminada',
    ];

    protected $table = 'movimientos_partida';

    protected $fillable = [
        'partida_id',
        'tipo',
        'tarima_origen_id',
        'tarima_destino_id',
        'motivo',
        'detalle',
        'user_id',
    ];

    protected static function booted(): void {
        static::updating(fn () => throw new LogicException('El historial de movimientos no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial de movimientos no se borra.'));
    }

    public function partida(): BelongsTo {
        return $this->belongsTo(Partida::class);
    }

    public function origen(): BelongsTo {
        return $this->belongsTo(Tarima::class, 'tarima_origen_id');
    }

    public function destino(): BelongsTo {
        return $this->belongsTo(Tarima::class, 'tarima_destino_id');
    }

    public function usuario(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }
}
