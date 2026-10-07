<?php

namespace App\KpiApi;

use App\KpiApi\Contracts\Kpi;
use LogicException;

final class KpiRegistry {
    /** @var array<string, array<string, Kpi>> */
    private array $cache = [];

    /** @return array<string, Kpi> clave => KPI */
    public function deVersion(string $version): array {
        if (isset($this->cache[$version])) {
            return $this->cache[$version];
        }

        $kpis = [];
        foreach (config("kpi-api.versiones.{$version}", []) as $clase) {
            $kpi = app($clase);

            if (!$kpi instanceof Kpi) {
                throw new LogicException("{$clase} no implementa " . Kpi::class);
            }
            if (isset($kpis[$kpi->clave()])) {
                throw new LogicException("Clave de KPI duplicada en {$version}: {$kpi->clave()}");
            }

            $kpis[$kpi->clave()] = $kpi;
        }

        return $this->cache[$version] = $kpis;
    }

    public function buscar(string $version, string $clave): ?Kpi {
        return $this->deVersion($version)[$clave] ?? null;
    }

    public static function definicion(Kpi $kpi, string $version): array {
        $parametros = [];
        foreach ($kpi->parametros() as $nombre => $p) {
            $parametros[] = [
                'nombre' => $nombre,
                'tipo' => $p['tipo'],
                'requerido' => (bool) ($p['requerido'] ?? false),
                'default' => $p['default'] ?? null,
                'descripcion' => $p['descripcion'],
            ];
        }

        $filtros = [];
        foreach ($kpi->filtros() as $nombre => $descripcion) {
            $filtros[] = ['nombre' => $nombre, 'descripcion' => $descripcion];
        }

        $campos = [];
        foreach ($kpi->campos() as $nombre => $c) {
            $campos[] = ['nombre' => $nombre, 'tipo' => $c['tipo'], 'descripcion' => $c['descripcion']];
        }

        return [
            'clave' => $kpi->clave(),
            'nombre' => $kpi->nombre(),
            'descripcion' => $kpi->descripcion(),
            'unidad' => $kpi->unidad(),
            'endpoint' => "/api/{$version}/kpis/{$kpi->clave()}",
            'agrupaciones' => array_values($kpi->agrupaciones()),
            'agrupacion_default' => $kpi->agrupacionPorDefecto(),
            'filtros' => $filtros,
            'parametros' => $parametros,
            'campos' => $campos,
            'paginable' => $kpi->paginable(),
        ];
    }
}
