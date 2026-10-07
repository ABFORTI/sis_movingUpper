<?php

namespace App\Kpis;

use App\KpiApi\KpiQuery;
use App\Models\Impresion;
use App\Models\NumeroSerie;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Desglose por usuario de la actividad del periodo, con las mismas reglas de
 * conteo que los KPIs de serie: la suma de una columna coincide con el
 * agrupacion=total del KPI equivalente. Son cuatro GROUP BY user_id (uno por
 * tabla) más una consulta de nombres, sin importar cuántos usuarios haya.
 */
class ProductividadPorUsuario extends MovingUpperKpi {
    public function clave(): string { return 'productividad-por-usuario'; }
    public function nombre(): string { return 'Productividad por usuario'; }
    public function descripcion(): string { return 'N/S escaneados, SKUs capturados, tarimas guardadas e impresiones confirmadas por cada usuario en el periodo, de mayor a menor actividad.'; }
    public function unidad(): string { return 'usuarios'; }

    public function filtros(): array {
        return [
            'usuario' => 'ID del usuario (users.id).',
        ];
    }

    public function paginable(): bool {
        return true;
    }

    public function campos(): array {
        return [
            'usuario_id' => ['tipo' => 'integer|null', 'descripcion' => 'ID del usuario; null agrupa la actividad de usuarios que ya fueron eliminados.'],
            'usuario_nombre' => ['tipo' => 'string|null', 'descripcion' => 'Nombre del usuario; null si fue eliminado.'],
            'total_numeros_serie' => ['tipo' => 'integer', 'descripcion' => 'N/S que escaneó (como numeros-serie-escaneados).'],
            'total_registros' => ['tipo' => 'integer', 'descripcion' => 'SKUs que capturó (como skus-registrados).'],
            'total_tarimas' => ['tipo' => 'integer', 'descripcion' => 'Tarimas que guardó (como tarimas-registradas).'],
            'total_impresiones' => ['tipo' => 'integer', 'descripcion' => 'Impresiones que confirmó (como etiquetas-impresas → total_impresiones).'],
        ];
    }

    public function calcular(KpiQuery $consulta): array {
        $conteos = [
            'total_numeros_serie' => $this->porUsuario($consulta, NumeroSerie::query(), 'numeros_serie.created_at', 'numeros_serie.user_id'),
            'total_registros' => $this->porUsuario($consulta, Registro::query(), 'registros.created_at', 'registros.user_id'),
            'total_tarimas' => $this->porUsuario($consulta, Tarima::query()->guardadas(), 'tarimas.guardada_at', 'tarimas.guardada_by'),
            'total_impresiones' => $this->porUsuario($consulta, Impresion::query(), 'impresiones.created_at', 'impresiones.user_id'),
        ];

        // '' agrupa user_id NULL (usuario eliminado: las FK son nullOnDelete).
        $ids = collect($conteos)->flatMap(fn (array $porUsuario) => array_keys($porUsuario))->unique();
        $nombres = User::whereIn('id', $ids->filter(fn ($id) => $id !== '')->all())->pluck('name', 'id');

        $renglones = $ids->map(fn ($id) => [
            'usuario_id' => $id === '' ? null : (int) $id,
            'usuario_nombre' => $id === '' ? null : $nombres->get($id),
        ] + array_map(fn (array $porUsuario) => $porUsuario[$id] ?? 0, $conteos))->all();

        usort($renglones, fn (array $a, array $b) => [$b['total_numeros_serie'], $b['total_registros'], $b['total_tarimas'], $b['total_impresiones'], $a['usuario_id'] ?? PHP_INT_MAX]
            <=> [$a['total_numeros_serie'], $a['total_registros'], $a['total_tarimas'], $a['total_impresiones'], $b['usuario_id'] ?? PHP_INT_MAX]);

        return $renglones;
    }

    /** @return array<int|string, int> user_id ('' si es NULL) => conteo */
    private function porUsuario(KpiQuery $consulta, Builder $query, string $fecha, string $usuario): array {
        $consulta->aplicarPeriodo($query, $fecha);
        if ($usuarios = $this->usuarios($consulta)) {
            $query->whereIn($usuario, $usuarios);
        }

        $conteos = [];
        foreach ($query->toBase()->selectRaw("{$usuario} as usuario, COUNT(*) as total")->groupBy($usuario)->get() as $fila) {
            $conteos[$fila->usuario ?? ''] = (int) $fila->total;
        }

        return $conteos;
    }
}
