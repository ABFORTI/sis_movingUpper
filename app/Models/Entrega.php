<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Un Excel de inventario entregado al cliente (Reporte Diario: el trabajo de un día). Inmutable. */
class Entrega extends Model {
    public const UPDATED_AT = null;

    protected $fillable = [
        'mudanza_id',
        'version',
        'fecha',
        'archivo',
        'sha256',
        'tamano',
        'total_partidas',
        'nuevas',
        'cambiadas',
        'salieron',
        'notas',
        'generada_by',
    ];

    protected function casts(): array {
        return [
            'version' => 'integer',
            'fecha' => 'date',
            'total_partidas' => 'integer',
            'nuevas' => 'integer',
            'cambiadas' => 'integer',
            'salieron' => 'integer',
        ];
    }

    protected static function booted(): void {
        static::updating(fn () => throw new LogicException('Una entrega no se modifica: genera una versión nueva.'));
        static::deleting(fn () => throw new LogicException('Una entrega no se borra.'));
    }

    public function mudanza(): BelongsTo {
        return $this->belongsTo(Mudanza::class);
    }

    public function generadaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'generada_by');
    }

    public function filas(): HasMany {
        return $this->hasMany(EntregaPartida::class)->orderBy('consecutivo');
    }

    /** "MUD-0001_Reporte_Diario_2026-10-19_v3.xlsx"; las entregas anteriores (mudanza completa): "MUD-0001_Entrega_v3.xlsx". */
    public function getNombreArchivoAttribute(): string {
        return $this->fecha
            ? "{$this->mudanza->folio}_Reporte_Diario_{$this->fecha->format('Y-m-d')}_v{$this->version}.xlsx"
            : "{$this->mudanza->folio}_Entrega_v{$this->version}.xlsx";
    }
}
