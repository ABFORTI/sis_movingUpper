<?php

namespace App\Models;

use App\Enums\VisibilidadManual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Manual extends Model {

    public const DISCO = 'manuales';

    public const TAMANO_MAXIMO_KB = 20480;

    protected $table = 'manuales';

    protected $fillable = [
        'nombre',
        'visible_para',
        'archivo',
        'tamano',
        'subido_by',
    ];

    protected function casts(): array {
        return [
            'visible_para' => VisibilidadManual::class,
        ];
    }

    public function subidoPor(): BelongsTo {
        return $this->belongsTo(User::class, 'subido_by');
    }


    public function scopeVisiblesPara(Builder $query, User $usuario): Builder {
        if ($usuario->isAdmin()) {
            return $query;
        }

        $visibilidades = $usuario->roles
            ->flatMap(fn (Role $rol) => VisibilidadManual::paraRol($rol->slug))
            ->map(fn (VisibilidadManual $v) => $v->value)
            ->unique()
            ->values();

        return $query->whereIn('visible_para', $visibilidades);
    }

    public function esVisiblePara(User $usuario): bool {
        return $usuario->isAdmin()
            || $usuario->roles->contains(fn (Role $rol) => in_array($rol->slug, $this->visible_para->roles(), true));
    }

    public function nombreDescarga(): string {
        return (Str::slug($this->nombre) ?: 'manual').'.pdf';
    }

    public function tamanoLegible(): string {
        return $this->tamano >= 1048576
            ? number_format($this->tamano / 1048576, 1).' MB'
            : max(1, (int) round($this->tamano / 1024)).' KB';
    }
}
