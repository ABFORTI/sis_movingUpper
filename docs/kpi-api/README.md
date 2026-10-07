# Análisis de la API REST v1 de Cotizaciones (origen del estándar)

Revisión de `/api/v1` (`routes/api.php`, `app/Http/Controllers/Api/V1/`,
`app/Services/KpiService.php`, `bootstrap/app.php`, `IssueApiToken`) para separar
lo que es **arquitectura reutilizable** de lo que es **propio de Cotizaciones**.

## Lo que se conservó como estándar

| Elemento de v1 | Decisión |
|---|---|
| Sanctum, `Authorization: Bearer`, tokens de acceso personal | Se conserva igual |
| Ability única `kpis:read`; el rol del usuario no da acceso | Se conserva igual |
| Cuenta de servicio dedicada + `php artisan api:token` | Se conserva; el comando pasa a formar parte del kit |
| Solo `GET`, escrituras solo por la aplicación web | Se conserva y ahora se prueba (405) |
| `ForceJsonResponse`: errores en JSON aunque no haya `Accept` | Se conserva, acotado a las versiones del estándar (`ForzarRespuestaJson`) |
| Versión en URL con prefijo `/api/v1` | Se conserva |
| Exclusión de la papelera (soft deletes) | Se conserva como regla de seguridad |
| Límite de 60 peticiones/min | Se conserva, ahora **por token** |

## Lo que se corrigió o normalizó

| Hallazgo en v1 | Problema para un central multi-sistema | Estándar |
|---|---|---|
| **El límite de 60/min no se aplicaba**: el limitador `api` estaba definido pero `throttle:api` nunca se asignaba a las rutas | La documentación prometía algo que no ocurría | Corregido en v1 (`throttle:api`) y aplicado en el estándar (`throttle:kpi-api`) |
| Respuestas con formas distintas: `/kpis/resumen` devuelve un objeto plano; los demás KPIs `{data: [...]}`; los listados `{data, links, meta}` | El central tendría que conocer la forma de cada endpoint | Sobre único con las mismas 9 claves para todo |
| Errores: `{"message": "..."}` y, en validación, `{message, errors}` | Sin código estable ni campo | `errores: [{codigo, mensaje, campo}]` con catálogo cerrado |
| Periodo implícito: `meses=12` / `meses=6` contados desde hoy | No se puede pedir un rango concreto ni reproducir un cálculo | `fecha_inicio`/`fecha_fin` explícitos y el periodo efectivo en la respuesta |
| Agrupación fija por mes | No sirve para tableros diarios/semanales | `agrupacion` estándar con etiquetas fijas |
| Meses sin datos desaparecen de la serie | El central tiene que rellenar huecos | Series sin huecos |
| Parámetros con nombres variados: `desde`/`hasta`, `vendedor_id`, `per_page`, `limite`, `meses` | Cada sistema inventaría los suyos | Nombres reservados: `fecha_inicio`, `fecha_fin`, `usuario`, `pagina`, `por_pagina`… |
| Parámetros desconocidos se ignoran en silencio; `meses=abc` se convierte en 0 | Un error de escritura produce datos "válidos" pero incorrectos | Validación estricta con 422 |
| `(int) $request->query('meses')` sin límites | Consultas potencialmente enormes | Rango máximo y máximo de puntos por serie |
| Sin identificación del sistema origen ni del KPI en la respuesta | El central no puede validar qué recibió | `sistema`, `kpi`, `version_api` en el sobre y en headers |
| Sin catálogo | Integrar un KPI requiere leer documentación | `GET /kpis` autodescriptivo |
| Sin id de correlación | Difícil rastrear un error entre sistemas | `X-Request-Id` / `metadatos.id_peticion` |
| Mezcla de recursos crudos (`/cotizaciones`, `/costeos`, `/usuarios`) con KPIs | No son KPIs; su forma depende del modelo de datos | Fuera del estándar; quedan solo en v1 |

## Lo que es exclusivo de Cotizaciones

- Los KPIs: resumen por etapa (pendiente → en Costeos → completada), cotizaciones
  creadas, top clientes, desempeño de Costeos, cotizaciones por vendedor.
- Las fuentes: tabla `cotizaciones` (`created_at`, `enviado_a_costeos`,
  `enviado_a_ventas`, `fecha_envio_ventas`, `fecha_envio_costeos`, `dias_meta`, `cliente`, `user_id`).
- Las reglas de negocio: `DiasHabilesCalculator` (corte a las 16:00, sin fines de
  semana) y cumplimiento contra `dias_meta`.
- El significado de los filtros: `usuario` = vendedor (`users.id`); `cliente` = nombre
  exacto (no hay catálogo de clientes con ID).

## Decisión de versionado para Cotizaciones

Cambiar el formato de `/api/v1` rompería a cualquier consumidor actual. Por la
propia regla del estándar (§14), el formato nuevo se publica en **`/api/v2`**.
`/api/v1` sigue funcionando sin cambios de formato (ver [../api-v1.md](../api-v1.md)).
Los sistemas nuevos empiezan directamente en `v1` con el estándar.

Correspondencia v1 → v2:

| v1 | v2 |
|---|---|
| `GET /kpis/resumen` | `GET /kpis/resumen-cotizaciones` (ahora por periodo; para todo el histórico, enviar `fecha_inicio` antigua) |
| `GET /kpis/cotizaciones-por-mes?meses=12` | `GET /kpis/cotizaciones-creadas?agrupacion=mes` |
| `GET /kpis/top-clientes?limite=5` | `GET /kpis/top-clientes?limite=5` |
| `GET /kpis/seguimiento-costeos?meses=6` | `GET /kpis/seguimiento-costeos?fecha_inicio=...` |
| — | `GET /kpis/cotizaciones-por-vendedor` (nuevo) |
| `/cotizaciones`, `/costeos`, `/usuarios` | Sin equivalente (no son KPIs) |

## Sistemas registrados

Claves de sistema asignadas (únicas en la empresa, inmutables):

| Clave | Sistema | Versión vigente | Catálogo |
|---|---|---|---|
| `cotizaciones` | Sistema de Cotizaciones | `v2` | Repositorio de Cotizaciones |
| `movingupper` | MovingUpper — WMS Upper Logistics Qro | `v3` | [sistemas/movingupper.md](sistemas/movingupper.md) |
