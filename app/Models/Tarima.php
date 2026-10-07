<?php

namespace App\Models;

use App\Enums\TipoRegistro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tarima extends Model {
    use HasUuids;

    protected $fillable = [
        'created_by',
    ];

    protected function casts(): array {
        return [
            'impresa_at' => 'datetime',
            'guardada_at' => 'datetime',
            'consecutivo_inicial' => 'integer',
        ];
    }

    public function uniqueIds(): array {
        return ['uuid'];
    }

    public function getRouteKeyName(): string {
        return 'uuid';
    }

    public function registros(): HasMany {
        return $this->hasMany(Registro::class)->orderBy('id');
    }

    public function creador(): BelongsTo {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function guardadaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'guardada_by');
    }

    /** Tarimas en captura (la vista de escaneo). */
    public function scopeTemporales(Builder $query): Builder {
        return $query->whereNull('guardada_at');
    }

    /** Tarimas que ya pasaron al historial. */
    public function scopeGuardadas(Builder $query): Builder {
        return $query->whereNotNull('guardada_at');
    }

    /**
     * Misma regla que TarimaPolicy::view, pero como consulta: admin y auditor ven
     * todas; el operador solo las que creó (temporales y guardadas).
     */
    public function scopeVisiblesPara(Builder $query, User $usuario): Builder {
        if ($usuario->puedeVerTodasLasTarimas()) {
            return $query;
        }

        return $query->where('created_by', $usuario->id);
    }

    /**
     * Filtros del historial (tarima, descripción, producto destino). Filtran
     * tarimas completas; los consecutivos de sus piezas no cambian.
     *
     * @param  array{tarima?: ?string, descripcion?: ?string, destino?: ?string}  $filtros
     */
    public function scopeFiltrosHistorial(Builder $query, array $filtros): Builder {
        $like = fn (string $valor) => '%'.addcslashes($valor, '%_\\').'%';

        return $query
            ->when($filtros['tarima'] ?? null, function (Builder $q, string $valor) {
                $numero = preg_replace('/\D/', '', $valor);
                $q->where('id', $numero === '' ? 0 : (int) $numero);
            })
            ->when($filtros['descripcion'] ?? null, fn (Builder $q, string $valor) => $q->whereHas(
                'registros',
                fn (Builder $r) => $r->where('descripcion', 'like', $like($valor))
            ))
            ->when($filtros['destino'] ?? null, fn (Builder $q, string $valor) => $q->whereHas(
                'registros.destino',
                fn (Builder $d) => $d->where('codigo', 'like', $like($valor))
                    ->orWhere('nombre', 'like', $like($valor))
            ));
    }

    /**
     * Consecutivo persistente de la pieza en la posición $posicion (base 0) de
     * TarimaService::filas(). Null mientras la tarima no se ha guardado.
     */
    public function consecutivo(int $posicion): ?int {
        return $this->consecutivo_inicial === null ? null : $this->consecutivo_inicial + $posicion;
    }

    public function estaImpresa(): bool {
        return $this->impresa_at !== null;
    }

    public function estaGuardada(): bool {
        return $this->guardada_at !== null;
    }

    public function getNombreAttribute(): string {
        return "Tarima {$this->id}";
    }

    public function piezas(): int {
        return $this->registros->sum(
            fn (Registro $r) => $r->tipo === TipoRegistro::Padre ? $r->numerosSerie->count() : 1
        );
    }

    public function esSoloPadre(): bool {
        return $this->registros->isNotEmpty()
            && $this->registros->every(fn (Registro $r) => $r->tipo === TipoRegistro::Padre);
    }

    public function cargarDetalle(): static {
        return $this->loadMissing(['registros.destino', 'registros.numerosSerie']);
    }
}
