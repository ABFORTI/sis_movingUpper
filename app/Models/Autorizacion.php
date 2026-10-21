<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;


class Autorizacion extends Model {
    public const PENDIENTE = 'pendiente';
    public const VIGENTE = 'vigente';
    public const RECHAZADA = 'rechazada';
    public const REVOCADA = 'revocada';

    public const ESTADOS = [
        self::PENDIENTE => 'Por validar',
        self::VIGENTE => 'Vigente',
        self::RECHAZADA => 'Rechazada',
        self::REVOCADA => 'Revocada',
    ];

    protected $table = 'autorizaciones';

    protected $fillable = [
        'mudanza_id',
        'folio',
        'documento',
        'fecha_documento',
        'autorizado_por',
        'autorizado_cargo',
        'estado',
        'total_partidas',
        'hash_alcance',
        'notas',
        'registrada_by',
        'validada_by',
        'validada_at',
        'motivo_cierre',
        'cerrada_by',
        'cerrada_at',
    ];

    protected function casts(): array {
        return [
            'fecha_documento' => 'date',
            'validada_at' => 'datetime',
            'cerrada_at' => 'datetime',
            'total_partidas' => 'integer',
        ];
    }


    private const INMUTABLES = ['mudanza_id', 'folio', 'documento', 'fecha_documento', 'autorizado_por', 'autorizado_cargo', 'total_partidas', 'hash_alcance', 'registrada_by'];

    protected static function booted(): void {
        static::updating(function (self $a) {
            if ($a->isDirty(self::INMUTABLES)) {
                throw new LogicException('Una autorización no se edita: se rechaza o se revoca y se registra otra.');
            }
        });
        static::deleting(fn () => throw new LogicException('Una autorización no se borra.'));
    }

    public function mudanza(): BelongsTo {
        return $this->belongsTo(Mudanza::class);
    }


    public function partidas(): BelongsToMany {
        return $this->belongsToMany(Partida::class, 'autorizacion_partidas')
            ->withPivot(['consecutivo', 'sku', 'numero_serie', 'tarima_numero'])
            ->orderByPivot('consecutivo');
    }

    public function evidencias(): MorphMany {
        return $this->morphMany(Evidencia::class, 'evidenciable')->orderBy('id');
    }

    public function registradaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'registrada_by');
    }

    public function validadaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'validada_by');
    }

    public function cerradaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'cerrada_by');
    }

    public function scopeVigentes(Builder $query): Builder {
        return $query->where('estado', self::VIGENTE);
    }

    public function estaVigente(): bool {
        return $this->estado === self::VIGENTE;
    }

    public function estaPendiente(): bool {
        return $this->estado === self::PENDIENTE;
    }

    public function getEstadoNombreAttribute(): string {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    /**
     * SHA-256 del alcance: lista ordenada 
     *
     * @param iterable<array{partida_id: int, consecutivo: int, numero_serie: ?string}> $lineas
     */
    public static function hashAlcance(iterable $lineas): string {
        return hash('sha256', collect($lineas)
            ->sortBy('consecutivo')
            ->map(fn ($l) => "{$l['partida_id']}|{$l['consecutivo']}|{$l['numero_serie']}")
            ->join("\n"));
    }

    public function hashActual(): string {
        return self::hashAlcance($this->partidas()->get()->map(fn (Partida $p) => [
            'partida_id' => $p->id,
            'consecutivo' => $p->pivot->consecutivo,
            'numero_serie' => $p->pivot->numero_serie,
        ]));
    }
}
