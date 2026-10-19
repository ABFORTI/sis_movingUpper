<?php

namespace App\Services;

use App\Models\Bitacora;
use App\Models\Mudanza;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Registra acciones de negocio con quién, cuándo, desde dónde y por qué. Los
 * servicios lo llaman de forma explícita para que cada entrada tenga sentido
 * operativo ("tarima.anulada"), no un volcado genérico de cambios.
 */
class BitacoraService {
    public const TARIMA_ANULADA = 'tarima.anulada';
    public const TARIMA_VACIADA = 'tarima.vaciada';
    public const TARIMA_ELIMINADA = 'tarima.eliminada';
    public const EXCEL_HISTORIAL = 'excel.historial';

    public function __construct(private readonly Request $request) { }

    /** Si no se indica la mudanza, se toma de la entidad cuando la tiene (p. ej. una tarima). */
    public function registrar(string $accion, ?User $usuario, ?Model $entidad = null, array $datos = [], ?string $motivo = null, ?Mudanza $mudanza = null): Bitacora {
        return Bitacora::create([
            'mudanza_id' => $mudanza?->id ?? $entidad?->getAttribute('mudanza_id'),
            'user_id' => $usuario?->id,
            'accion' => $accion,
            'entidad_type' => $entidad ? class_basename($entidad) : null,
            'entidad_id' => $entidad?->getKey(),
            'datos' => $datos ?: null,
            'motivo' => $motivo,
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 252),
        ]);
    }
}
