<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** Impresora Zebra registrada por su MAC Bluetooth. */
class Impresora extends Model {
    protected $table = 'impresoras';

    protected $fillable = ['nombre', 'mac', 'nombre_bluetooth', 'ubicacion', 'activa'];

    protected function casts(): array {
        return ['activa' => 'boolean'];
    }

    /**
     * Acepta la MAC como venga (AC3FA4CDC515, ac-3f-a4-cd-c5-15, AC:3F:…) y la
     * deja como AA:BB:CC:DD:EE:FF. Devuelve null si no son 12 dígitos hexadecimales.
     */
    public static function normalizarMac(?string $mac): ?string {
        $hex = strtoupper((string) preg_replace('/[^0-9a-f]/i', '', (string) $mac));

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    protected function mac(): Attribute {
        return Attribute::set(fn (?string $valor) => self::normalizarMac($valor) ?? $valor);
    }

    public function scopeActivas(Builder $query): Builder {
        return $query->where('activa', true)->orderBy('nombre');
    }
}
