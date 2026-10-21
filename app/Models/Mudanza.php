<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Mudanza extends Model {
    public const BORRADOR = 'borrador';
    public const ACTIVA = 'activa';
    public const SUSPENDIDA = 'suspendida';
    public const EN_CIERRE = 'en_cierre';
    public const CERRADA = 'cerrada';
    public const CANCELADA = 'cancelada';


    public const EN_CURSO = [self::ACTIVA, self::SUSPENDIDA, self::EN_CIERRE];

    public const ESTADOS = [
        self::BORRADOR => 'Borrador',
        self::ACTIVA => 'Activa',
        self::SUSPENDIDA => 'Suspendida',
        self::EN_CIERRE => 'En cierre',
        self::CERRADA => 'Cerrada',
        self::CANCELADA => 'Cancelada',
    ];


    public const CONTADOR_FOLIO = 'folio_mudanzas';

    protected $table = 'mudanzas';

    protected $fillable = [
        'folio',
        'cliente',
        'notas',
        'estado',
        'created_by',
    ];

    protected function casts(): array {
        return [
            'activada_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    public function tarimas(): HasMany {
        return $this->hasMany(Tarima::class);
    }


    public function movimientos(): HasMany {
        return $this->hasMany(MudanzaMovimiento::class)->orderBy('id');
    }

    public function activadaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'activada_by');
    }

    public function cerradaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'cerrada_by');
    }

    public function scopeEnCurso(Builder $query): Builder {
        return $query->whereIn('estado', self::EN_CURSO);
    }

    public static function folioPara(int $numero): string {
        return sprintf('MUD-%04d', $numero);
    }

    public function estaEnCurso(): bool {
        return in_array($this->estado, self::EN_CURSO, true);
    }


    public function permiteCaptura(): bool {
        return $this->estado === self::ACTIVA;
    }


    public function permiteCerrarTarimas(): bool {
        return in_array($this->estado, [self::ACTIVA, self::EN_CIERRE], true);
    }

    public function getEstadoNombreAttribute(): string {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }


    public function getRutasAttribute(): string {
        $this->loadMissing('movimientos.origen', 'movimientos.destino');

        return $this->movimientos->map->nombre->join(' · ') ?: '—';
    }

    /** "MUD-0001 · Cliente" en encabezados. */
    public function getTituloAttribute(): string {
        return $this->cliente ? "{$this->folio} · {$this->cliente}" : $this->folio;
    }
}
