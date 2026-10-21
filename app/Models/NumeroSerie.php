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


    public function partida(): HasOne {
        return $this->hasOne(Partida::class);
    }


    public function inventarioItem(): BelongsTo {
        return $this->belongsTo(InventarioItem::class);
    }


    public function incidenciaPendiente(): HasOne {
        return $this->hasOne(Incidencia::class)->pendientesDeRevision();
    }

    public function tieneAnomalia(): bool {
        return $this->anomalia_at !== null;
    }


    public function etiquetaPendiente(): bool {
        return $this->etiqueta_impresa_at === null;
    }


    public function pendienteRevision(): bool {
        return $this->incidenciaPendiente !== null;
    }


    public function getCoincidenciaNombreAttribute(): ?string {
        return InventarioItem::CAMPOS_SERIE[$this->coincidencia] ?? null;
    }
}
