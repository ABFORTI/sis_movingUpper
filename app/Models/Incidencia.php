<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Un hallazgo o diferencia de la mudanza, con su historial y evidencias. */
class Incidencia extends Model {
    public const ABIERTA = 'abierta';
    public const RESUELTA = 'resuelta';

    /** Los únicos estados operativos. */
    public const ESTADOS = [
        self::ABIERTA => 'Abierta',
        self::RESUELTA => 'Resuelta',
    ];

    /** Estados en los que la incidencia sigue viva (y puede bloquear la carga). */
    public const VIGENTES = [self::ABIERTA];

    public const NO_EN_INVENTARIO = 'no_en_inventario';
    public const DANIO = 'danio';

    /** Los únicos tipos que se registran hoy. */
    public const TIPOS = [
        self::NO_EN_INVENTARIO => 'No encontrado en inventario',
        self::DANIO => 'Producto dañado',
    ];

    /**
     * Tipos de incidencias anteriores a la simplificación. Ya no se registran,
     * pero las existentes conservan su tipo y se muestran con este nombre.
     */
    public const TIPOS_HISTORICOS = [
        'ns_duplicada' => 'N/S duplicada o inconsistente (histórico)',
        'sin_identificacion' => 'Producto sin identificación (histórico)',
        'no_en_sap' => 'Escaneado pero no está en SAP (histórico)',
        'no_encontrado' => 'En SAP pero no se encontró físicamente (histórico)',
        'diferencia_datos' => 'Diferencia de SKU, descripción o cantidad (histórico)',
        'etiqueta' => 'Etiqueta dañada o ilegible (histórico)',
        'faltante' => 'Faltante en carga o recepción (histórico)',
        'no_esperada' => 'Llegó sin venir en el tráiler (histórico)',
        'otro' => 'Otro (histórico)',
    ];

    public const RESOLUCIONES = [
        'corregido_upper' => 'Corregido en el registro de Upper',
        'corregido_sap' => 'Corregido en SAP por el cliente',
        'aceptado_cliente' => 'Aceptado por el cliente con la diferencia',
        'excluido_traslado' => 'Excluido del traslado',
        'localizado' => 'Producto localizado',
        'descartada' => 'Descartada: no era una incidencia',
        'otro' => 'Otro',
    ];

    /** Resoluciones que son decisión del cliente: exigen evidencia adjunta (correo, documento). */
    public const RESOLUCIONES_DEL_CLIENTE = ['corregido_sap', 'aceptado_cliente', 'excluido_traslado'];

    protected $fillable = [
        'mudanza_id',
        'folio',
        'tipo',
        'estado',
        'bloquea_traslado',
        'numero_serie_id',
        'tarima_id',
        'almacen_id',
        'sku',
        'numero_serie',
        'titulo',
        'descripcion',
        'responsable_id',
        'tipo_resolucion',
        'resolucion',
        'creada_by',
        'resuelta_at',
        'resuelta_by',
    ];

    protected function casts(): array {
        return [
            'bloquea_traslado' => 'boolean',
            'resuelta_at' => 'datetime',
        ];
    }

    public function mudanza(): BelongsTo {
        return $this->belongsTo(Mudanza::class);
    }

    public function partidas(): BelongsToMany {
        return $this->belongsToMany(Partida::class, 'incidencia_partida')->orderBy('consecutivo');
    }

    public function eventos(): HasMany {
        return $this->hasMany(IncidenciaEvento::class)->orderBy('id');
    }

    public function evidencias(): MorphMany {
        return $this->morphMany(Evidencia::class, 'evidenciable')->orderBy('id');
    }

    /** N/S capturada a la que se refiere (incidencias de "no encontrado en inventario"). */
    public function numeroSerie(): BelongsTo {
        return $this->belongsTo(NumeroSerie::class)->withTrashed();
    }

    public function tarima(): BelongsTo {
        return $this->belongsTo(Tarima::class);
    }

    public function almacen(): BelongsTo {
        return $this->belongsTo(Almacen::class);
    }

    public function responsable(): BelongsTo {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function creadaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'creada_by');
    }

    public function resueltaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'resuelta_by');
    }

    public function scopeVigentes(Builder $query): Builder {
        return $query->whereIn('estado', self::VIGENTES);
    }

    /** Abiertas de "no encontrado en inventario": las piezas quedan señaladas como pendientes de revisión. */
    public function scopePendientesDeRevision(Builder $query): Builder {
        return $query->where('tipo', self::NO_EN_INVENTARIO)->where('estado', self::ABIERTA);
    }

    public function estaVigente(): bool {
        return in_array($this->estado, self::VIGENTES, true);
    }

    public function getEstadoNombreAttribute(): string {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    public function getTipoNombreAttribute(): string {
        return self::TIPOS[$this->tipo] ?? self::TIPOS_HISTORICOS[$this->tipo] ?? $this->tipo;
    }
}
