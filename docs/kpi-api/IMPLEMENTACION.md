# Guía de implementación — nueva API de KPIs en otro sistema

Cómo publicar la API de KPIs de un sistema nuevo (Producción, Inventarios, …)
reutilizando la arquitectura del estándar, **sin diseñar nada desde cero**.
Lo normativo está en [ESTANDAR.md](ESTANDAR.md); aquí está el "cómo".

Tiempo estimado: medio día para la infraestructura + el tiempo de cada KPI.

## 0. Requisitos

- Laravel 11+ y PHP 8.2+.
- Laravel Sanctum: `php artisan install:api` (instala Sanctum, crea `routes/api.php`
  y la migración `personal_access_tokens`). Luego `php artisan migrate`.
- El modelo `User` usa el trait `Laravel\Sanctum\HasApiTokens`.
- `APP_TIMEZONE` configurado con la zona horaria real del negocio (p. ej. `America/Mexico_City`).

Si el sistema **no** es Laravel, debe replicar el contrato de ESTANDAR.md completo
y pasar una prueba de conformidad equivalente antes de conectarse al central.

## 1. Copiar el kit (sin modificar)

Desde el repositorio de Cotizaciones, copiar tal cual:

| Origen | Contenido |
|---|---|
| `app/KpiApi/` (todo el directorio) | Rutas, controlador, validación, sobre JSON, errores, periodos, contrato `Kpi` |
| `app/Console/Commands/IssueApiToken.php` | Comando `php artisan api:token` |
| `tests/Feature/KpiApi/ConformidadEstandarTest.php` | Prueba de conformidad |

**No se edita nada dentro de `app/KpiApi/`.** Si algo del estándar necesita
cambiar, se cambia en el estándar (nueva versión del estándar) y se vuelve a
copiar a todos los sistemas. Así todas las APIs se comportan igual.

## 2. Conectar (4 líneas)

`bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\KpiApi\KpiApiServiceProvider::class,
];
```

`bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        // ...alias propios del sistema...
        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
    ]);
    \App\KpiApi\KpiApi::middleware($middleware);
})
->withExceptions(function (Exceptions $exceptions) {
    \App\KpiApi\KpiApi::excepciones($exceptions);   // PRIMERO, antes de otros render()
    // ...manejo de errores propio del sistema...
})
```

> `alias()` de Laravel 11 **reemplaza** la lista: el alias `abilities` va en la
> misma llamada que los alias propios del sistema.

`routes/api.php`:

```php
\App\KpiApi\KpiApi::rutas();
```

## 3. Configurar el sistema (`config/kpi-api.php`)

Único archivo de configuración que cambia entre sistemas:

```php
<?php

return [
    'sistema' => [
        'clave' => env('KPI_API_SISTEMA_CLAVE', 'produccion'),   // única en la empresa, kebab-case, inmutable
        'nombre' => env('KPI_API_SISTEMA_NOMBRE', 'Sistema de Producción'),
    ],

    'limite_por_minuto' => (int) env('KPI_API_LIMITE_POR_MINUTO', 60),

    'versiones' => [
        'v1' => [
            App\Kpis\PiezasProducidas::class,
            App\Kpis\EficienciaPorTurno::class,
        ],
    ],
];
```

Claves de sistema ya asignadas: `cotizaciones`. Registrar las nuevas en
[README.md](README.md#sistemas-registrados) para evitar duplicados.

## 4. Mapear los filtros generales a este sistema

Crear una clase base para los KPIs del sistema que traduzca los filtros
generales a sus columnas. Es la pieza que contiene el conocimiento "local":

```php
<?php

namespace App\Kpis;

use App\KpiApi\AbstractKpi;
use App\KpiApi\KpiQuery;
use App\Models\OrdenProduccion;
use Illuminate\Database\Eloquent\Builder;

abstract class ProduccionKpi extends AbstractKpi
{
    public function filtros(): array
    {
        return [
            'area'     => 'ID de la línea de producción (lineas.id).',
            'producto' => 'SKU del producto.',
            'turno'    => 'Clave del turno: M, V o N.',
        ];
    }

    protected function ordenes(KpiQuery $consulta): Builder
    {
        $query = OrdenProduccion::query();
        $consulta->aplicarPeriodo($query, 'fecha_cierre');
        $consulta->aplicarFiltro($query, 'area', 'linea_id');
        $consulta->aplicarFiltro($query, 'producto', 'sku');
        $consulta->aplicarFiltro($query, 'turno', 'turno');

        return $query;
    }
}
```

Solo se declaran los filtros que tienen sentido en el sistema. Un KPI puede
quitar los que no apliquen (ver `TopClientes::filtros()` en Cotizaciones).

## 5. Escribir cada KPI

Plantilla (serie de tiempo):

```php
<?php

namespace App\Kpis;

use App\KpiApi\KpiQuery;
use App\KpiApi\Periodo;
use Illuminate\Support\Collection;

class PiezasProducidas extends ProduccionKpi
{
    public function clave(): string       { return 'piezas-producidas'; }
    public function nombre(): string      { return 'Piezas producidas'; }
    public function descripcion(): string { return 'Piezas buenas y rechazadas por periodo, según fecha de cierre de la orden.'; }
    public function unidad(): string      { return 'piezas'; }

    public function agrupaciones(): array
    {
        // La primera es la default.
        return [Periodo::DIA, Periodo::SEMANA, Periodo::MES, Periodo::TOTAL];
    }

    public function parametros(): array
    {
        return [
            'incluir_retrabajo' => ['tipo' => 'boolean', 'default' => false, 'descripcion' => 'Cuenta piezas de órdenes de retrabajo.'],
        ];
    }

    public function campos(): array
    {
        return [
            'piezas_buenas'         => ['tipo' => 'integer', 'descripcion' => 'Piezas aprobadas por calidad.'],
            'piezas_rechazadas'     => ['tipo' => 'integer', 'descripcion' => 'Piezas rechazadas.'],
            'porcentaje_rechazo'    => ['tipo' => 'number|null', 'descripcion' => 'rechazadas / total × 100.'],
        ];
    }

    public function calcular(KpiQuery $consulta): array
    {
        $query = $this->ordenes($consulta);
        if (!$consulta->parametro('incluir_retrabajo')) {
            $query->where('es_retrabajo', false);
        }

        return $this->agruparPorPeriodo(
            $consulta,
            $query->get(['fecha_cierre', 'piezas_buenas', 'piezas_rechazadas']),
            fn ($orden) => $orden->fecha_cierre,
            fn (Collection $g) => [
                'piezas_buenas'      => (int) $g->sum('piezas_buenas'),
                'piezas_rechazadas'  => (int) $g->sum('piezas_rechazadas'),
                'porcentaje_rechazo' => $this->porcentaje($g->sum('piezas_rechazadas'), $g->sum('piezas_buenas') + $g->sum('piezas_rechazadas')),
            ]
        );
    }
}
```

Para KPIs de **tabla** (ranking, desglose por entidad): `agrupaciones()` =
`[Periodo::TOTAL]`, `calcular()` devuelve un renglón por entidad, y
`paginable()` = `true` si puede haber muchas filas (ver `CotizacionesPorVendedor`).

Reglas al escribir `calcular()`:

- Solo lectura. Nada de `save()`, `update()`, `delete()`, jobs, eventos que escriban, correos.
- Excluir registros eliminados lógicamente.
- Reutilizar los servicios/calculadoras que ya usa el sistema en sus pantallas,
  para que el número del central coincida con el que ve el usuario.
- `null` cuando un valor no se puede calcular; nunca `0` inventado.
- Devolver los campos exactamente en el orden declarado en `campos()`.
- Si un renglón referencia una entidad, incluir `<entidad>_id` y `<entidad>_nombre`.

## 6. Probar

1. En `ConformidadEstandarTest`, ajustar **solo** `cuentaDeServicio()` si el
   modelo `User` exige campos (en Cotizaciones: `role`).
2. Escribir pruebas con valores concretos por KPI (ver
   `tests/Feature/KpiApi/CotizacionesKpisTest.php`).
3. `php artisan test tests/Feature/KpiApi` debe pasar completo.

La prueba de conformidad verifica automáticamente, para **cada** KPI
configurado: sobre JSON, headers, 401/403/404/405/422/429, catálogo válido,
nombres reservados, series sin huecos en cada agrupación, campos iguales a los
declarados, validación estricta y periodo por defecto.

## 7. Emitir el token y registrar en el central

```bash
# 1. Cuenta de servicio (una vez), con contraseña aleatoria que nadie conoce.
# 2. Token:
php artisan api:token kpi-central@empresa.com --name=kpi-central
#    → copiar el token: no se vuelve a mostrar.
# 3. Verificar:
curl -H "Authorization: Bearer <token>" https://produccion.empresa.com/api/v1/sistema
```

Entregar al equipo del sistema central, por un canal seguro: Base URL con
versión (`https://produccion.empresa.com/api/v1/`) y el token.

Revocar:

```bash
php artisan tinker --execute="\Laravel\Sanctum\PersonalAccessToken::where('name','kpi-central')->delete();"
```

## 8. Checklist de salida a producción

- [ ] `app/KpiApi/` copiado sin modificaciones (comparar con el de referencia).
- [ ] `config/kpi-api.php` con clave de sistema única y registrada en el README.
- [ ] `APP_TIMEZONE` correcto.
- [ ] Alias `abilities` registrado; `KpiApi::middleware`, `KpiApi::excepciones` y `KpiApi::rutas` conectados.
- [ ] `ConformidadEstandarTest` en verde.
- [ ] Pruebas con valores por KPI en verde.
- [ ] Ningún KPI escribe datos (revisión de código).
- [ ] HTTPS activo.
- [ ] Cuenta de servicio dedicada; token emitido con `kpis:read` únicamente.
- [ ] Catálogo documentado en `docs/kpi-api/sistemas/<clave>.md`.
- [ ] `GET /sistema` responde 200 desde la red del sistema central.

## Resumen — qué hace cada quien

| Paso | Estándar común (copiar) | Específico del sistema (escribir) |
|---|---|---|
| Autenticación, ability, solo lectura | `app/KpiApi/`, `api:token` | Crear la cuenta de servicio |
| Versionado y rutas | `KpiApi::rutas()` | Elegir versión inicial (`v1`) en config |
| Sobre JSON, errores, headers | `KpiResponse`, `ExceptionRenderer` | — |
| Parámetros generales, validación, periodos | `KpiQueryParser`, `Periodo` | — |
| Filtros generales | Nombres y formato | Mapeo a columnas (clase base del sistema) |
| KPIs | Contrato `Kpi`, `AbstractKpi` | Clases de KPI, consultas, reglas de negocio |
| Pruebas | `ConformidadEstandarTest` | Pruebas con valores de cada KPI |
