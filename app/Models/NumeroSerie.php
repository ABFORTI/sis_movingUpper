<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class NumeroSerie extends Model {

    use SoftDeletes;
    protected $table = 'numeros_serie';

    protected $fillable = [
        'registro_id',
        'numero_serie',
        'user_id',
        'deleted_by',
        'anomalia_at',
        'anomalia_by',
        'anomalia_nota',
        'inventario_item_id',
        'coincidencia',
        'etiqueta_impresa_at',
    ];

    protected function casts(): array {
        return [
            'anomalia_at' => 'datetime',
            'etiqueta_impresa_at' => 'datetime',
        ];
    }

    public function registro(): BelongsTo {
        return $this->belongsTo(Registro::class);
    }

    /** La pieza guardada (con consecutivo) de esta N/S; no existe mientras su embarque está en captura. */
    public function partida(): HasOne {
        return $this->hasOne(Partida::class);
    }

    /** Renglón del inventario con el que coincidió (null si se registró como incidencia). */
    public function inventarioItem(): BelongsTo {
        return $this->belongsTo(InventarioItem::class);
    }

    /** Incidencia abierta de "no encontrado en inventario" de esta N/S, si la hay. */
    public function incidenciaPendiente(): HasOne {
        return $this->hasOne(Incidencia::class)->pendientesDeRevision();
    }

    public function tieneAnomalia(): bool {
        return $this->anomalia_at !== null;
    }

    /** Todavía no se imprime la etiqueta de esta pieza (o se movió de embarque y hay que reimprimirla). */
    public function etiquetaPendiente(): bool {
        return $this->etiqueta_impresa_at === null;
    }

    /** Se registró sin coincidencia en el inventario y su incidencia sigue abierta. */
    public function pendienteRevision(): bool {
        return $this->incidenciaPendiente !== null;
    }

    /** "Número de serie del fabricante", etc.: el campo del inventario que coincidió. */
    public function getCoincidenciaNombreAttribute(): ?string {
        return InventarioItem::CAMPOS_SERIE[$this->coincidencia] ?? null;
    }
}
