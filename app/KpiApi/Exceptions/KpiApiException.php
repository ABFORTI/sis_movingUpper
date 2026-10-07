<?php

namespace App\KpiApi\Exceptions;

use RuntimeException;

class KpiApiException extends RuntimeException {
    public const NO_AUTENTICADO = 'NO_AUTENTICADO';
    public const SIN_PERMISO = 'SIN_PERMISO';
    public const NO_ENCONTRADO = 'NO_ENCONTRADO';
    public const KPI_NO_ENCONTRADO = 'KPI_NO_ENCONTRADO';
    public const METODO_NO_PERMITIDO = 'METODO_NO_PERMITIDO';
    public const PARAMETRO_INVALIDO = 'PARAMETRO_INVALIDO';
    public const PARAMETRO_DESCONOCIDO = 'PARAMETRO_DESCONOCIDO';
    public const FILTRO_NO_SOPORTADO = 'FILTRO_NO_SOPORTADO';
    public const RANGO_FECHAS_INVALIDO = 'RANGO_FECHAS_INVALIDO';
    public const SOLICITUD_INVALIDA = 'SOLICITUD_INVALIDA';
    public const LIMITE_PETICIONES = 'LIMITE_PETICIONES';
    public const ERROR_INTERNO = 'ERROR_INTERNO';
    public const SERVICIO_NO_DISPONIBLE = 'SERVICIO_NO_DISPONIBLE';

    /** @param array<int, array{codigo: string, mensaje: string, campo: ?string}> $errores */
    public function __construct(public readonly int $status, public readonly array $errores) {
        parent::__construct($errores[0]['mensaje'] ?? 'Error de la API de KPIs');
    }

    public static function uno(int $status, string $codigo, string $mensaje, ?string $campo = null): self {
        return new self($status, [self::error($codigo, $mensaje, $campo)]);
    }

    /** @return array{codigo: string, mensaje: string, campo: ?string} */
    public static function error(string $codigo, string $mensaje, ?string $campo = null): array {
        return ['codigo' => $codigo, 'mensaje' => $mensaje, 'campo' => $campo];
    }
}
