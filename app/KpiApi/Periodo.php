<?php

namespace App\KpiApi;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class Periodo {
    public const DIA = 'dia';
    public const SEMANA = 'semana';
    public const MES = 'mes';
    public const TRIMESTRE = 'trimestre';
    public const ANIO = 'anio';
    public const TOTAL = 'total';

    public const AGRUPACIONES = [self::DIA, self::SEMANA, self::MES, self::TRIMESTRE, self::ANIO, self::TOTAL];

    public static function inicioDeCubeta(CarbonInterface $fecha, string $agrupacion): CarbonImmutable {
        $f = CarbonImmutable::instance($fecha);

        return match ($agrupacion) {
            self::DIA => $f->startOfDay(),
            self::SEMANA => $f->startOfWeek(CarbonInterface::MONDAY),
            self::MES => $f->startOfMonth(),
            self::TRIMESTRE => $f->startOfQuarter(),
            self::ANIO => $f->startOfYear(),
            default => throw new \InvalidArgumentException("Agrupación sin cubetas: {$agrupacion}"),
        };
    }

    public static function etiqueta(CarbonInterface $fecha, string $agrupacion): string {
        return match ($agrupacion) {
            self::DIA => $fecha->format('Y-m-d'),
            self::SEMANA => sprintf('%d-W%02d', $fecha->isoWeekYear, $fecha->isoWeek),
            self::MES => $fecha->format('Y-m'),
            self::TRIMESTRE => $fecha->format('Y') . '-Q' . $fecha->quarter,
            self::ANIO => $fecha->format('Y'),
            default => throw new \InvalidArgumentException("Agrupación sin etiqueta: {$agrupacion}"),
        };
    }

    /**
     *
     * @return array<string, array{periodo: string, periodo_inicio: string, periodo_fin: string}>
     */
    public static function cubetas(CarbonInterface $inicio, CarbonInterface $fin, string $agrupacion): array {
        $resultado = [];
        $cursor = self::inicioDeCubeta($inicio, $agrupacion);

        while ($cursor->lte($fin)) {
            $siguiente = match ($agrupacion) {
                self::DIA => $cursor->addDay(),
                self::SEMANA => $cursor->addWeek(),
                self::MES => $cursor->addMonthNoOverflow(),
                self::TRIMESTRE => $cursor->addMonthsNoOverflow(3),
                self::ANIO => $cursor->addYear(),
            };

            $etiqueta = self::etiqueta($cursor, $agrupacion);
            $resultado[$etiqueta] = [
                'periodo' => $etiqueta,
                'periodo_inicio' => $cursor->max($inicio)->format('Y-m-d'),
                'periodo_fin' => $siguiente->subDay()->min($fin)->format('Y-m-d'),
            ];

            $cursor = $siguiente;
        }

        return $resultado;
    }

    public static function contarCubetas(CarbonInterface $inicio, CarbonInterface $fin, string $agrupacion): int {
        return $agrupacion === self::TOTAL ? 1 : count(self::cubetas($inicio, $fin, $agrupacion));
    }
}
