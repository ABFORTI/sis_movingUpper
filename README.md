<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Acerca de movingUpper

Sistema web planeado y desarrollado para llevar un mejor control del inventario de Upper Logistics Qro, donde se puede hacer lo siguiente:

- Registro del SKU del tipo de producto y el numero de serie de cada producto correspondiente a esa SKU
- Registro unicamente de los SKUs
- Guardar registros dentro del sistema
- Generar e imprimir una etiqueta zebra del registro realizado
-  Exportar una o todas las tarimas en un excel


## Implicaciones tecnicas

El sistema esta desarrollado con php, laravel y blade.
Base de datos mysql relacional

## Acciones de seguridad

El registro queda acargo unicamente del administrador, cada usuario debera iniciar sesion, ademas de que se aplicaron principios de seguridad como:
- Principio de responsabilidad unica
- Principio de abierto/cerrado
- Principio de sustitucion de Liskov
- Principio de inversion de dependencias
- Cofidencialidad
- Integridad
- Disponibilidad

## Cómo se aplica en el código

| Principio | Dónde |
|---|---|
| Responsabilidad única | Controladores delgados; reglas en `app/Services` (`EscaneoService`, `TarimaService`), validación en `app/Http/Requests`, SKU activo en `app/Support/SkuActivo` |
| Abierto/cerrado | Formatos de etiqueta en `app/Services/Etiquetas`: un formato nuevo es otra clase que implementa `GeneradorEtiqueta` y se registra en `AppServiceProvider` |
| Sustitución de Liskov | `EtiquetaQrZpl` y `EtiquetaTablaZpl` son intercambiables detrás de `GeneradorEtiqueta` |
| Inversión de dependencias | Los controladores dependen de `ExportadorTarimas` (interfaz), no de PhpSpreadsheet |
| Confidencialidad | Login obligatorio, contraseñas con hash, bloqueo tras 5 intentos, regeneración de sesión, usuarios inactivos expulsados, solo el admin da de alta usuarios, el QR expone un UUID con URL firmada |
| Integridad | Transacciones, índices únicos en BD (SKU por tarima, N/S en todo el sistema), CSRF, escapado de Blade, bitácora `user_id` / `deleted_by` |
| Disponibilidad | Sin dependencias de CDN: códigos de barras, QR y Excel se generan en el servidor; borrado lógico para operadores |

## Roles

- **Administrador**: todo lo del operador, alta/edición de usuarios, vaciar tarimas de forma **definitiva** y eliminar tarimas.
- **Operador**: escanear, crear tarimas, imprimir, exportar y vaciar tarimas con **borrado lógico** (queda en papelera con quién y cuándo, y las N/S se liberan para volver a escanearse).

## Instalación

```bash
composer install
cp .env.example .env        # configurar DB_*, APP_URL
php artisan key:generate
php artisan migrate --seed  # roles, destinos PROD-A..D y usuarios iniciales
```

Usuarios iniciales (cambiar las contraseñas al entrar): `desarrollo@ab-forti.com` (admin), `auditor@upperlogistics.mx` (auditor) y `prueba@upperlogistics.mx` (operador).

Pruebas: `php artisan test`.

## Despliegue en Hostinger

1. El dominio debe apuntar a la carpeta `public/`.
2. En `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tu-dominio` (con https).
3. **No cambies `APP_KEY` ni `APP_URL` después de imprimir etiquetas**: la firma de la URL del QR depende de ambos; si cambian, los QR ya impresos dejan de abrir.
4. MySQL/MariaDB con soporte de columnas generadas (MySQL 5.7+ / MariaDB 10.2+; Hostinger ya lo cumple).
5. `php artisan migrate --force --seed` y `php artisan optimize`.
6. Manuales: PHP debe aceptar subidas de al menos 20 MB (`upload_max_filesize` y `post_max_size` ≥ 20M). La carpeta `storage/app/private/manuales` debe poder escribirse y **no** debe quedar dentro de `public/`.

## Roles y manuales

| Funcionalidad | Administrador | Auditor | Operador |
|---|:-:|:-:|:-:|
| Crear y trabajar tarimas | Sí | Sí | Sí |
| Ver tarimas e historial de otros usuarios | Sí | Sí | No (solo las suyas) |
| Eliminar tarimas / vaciar definitivamente | Sí | No (borrado lógico) | No (borrado lógico) |
| Gestionar usuarios | Sí | No | No |
| Gestionar manuales | Sí | No | No |
| Ver manuales | Todos | Los de "Operador y Auditor" y "Solo Auditor" | Los de "Operador y Auditor" y "Solo Operador" |

La visibilidad se valida en el servidor (`TarimaPolicy`, `ManualPolicy` y los scopes `visiblesPara`), no solo en las vistas.

**Archivos de manuales**: solo PDF, **máximo 20 MB** (`Manual::TAMANO_MAXIMO_KB`). Se valida la extensión, el MIME detectado por el contenido y la firma `%PDF-`. Se guardan en el disco privado `manuales` (`storage/app/private/manuales`) con un nombre generado (UUID), y solo se entregan por `/manuales/{id}/ver` o `/descargar` después de comprobar el rol.

## Identidad visual

- Colores: `#1C156D` principal (navegación, botones, foco), `#F16522` acento (una llamada a la acción por pantalla, sección activa, SKU padre activo, avisos) y `#6DBC45` éxito (confirmaciones e indicadores). Las reglas y los tokens están al inicio de `public/css/wms.css`.
- Recursos: `public/images/upper_logo1.png` (empresa), `icon.png` (sistema) y `Euroquip4.png` (cliente, solo en etiquetas). Las etiquetas Zebra usan esos originales: **no los edites**.
- La interfaz usa recortes de esos logos en `public/images/brand/` (sin margen sobrante y con la proporción intacta) a través del componente `<x-brand>`. `favicon.ico`, `favicon-32.png` y `apple-touch-icon.png` salen de `icon.png`.

## API de KPIs

API REST de solo lectura para el sistema central de KPIs, bajo `/api/v3/` (`/sistema`, `/kpis`, `/kpis/{clave}`): N/S escaneados, tarimas registradas, SKUs registrados, etiquetas impresas y productividad por usuario, por día, semana, mes, trimestre o año.

- Acceso solo con token Bearer con la ability `kpis:read`: `php artisan api:token kpi-central@upperlogistics.mx --crear`. La sesión web no da acceso.
- Cada confirmación de impresión queda en la tabla `impresiones` (etiquetas y reimpresiones).
- Contrato común: [docs/kpi-api/ESTANDAR.md](docs/kpi-api/ESTANDAR.md). Catálogo, fórmulas y restricciones: **[docs/kpi-api/sistemas/movingupper.md](docs/kpi-api/sistemas/movingupper.md)**.

## Impresión Zebra ZQ620 (etiqueta horizontal 75 × 50 mm)

El servidor genera el ZPL (`/tarimas/{uuid}/etiqueta.zpl`), pero como está en Hostinger **no puede llegar a la impresora**. El navegador del dispositivo lo entrega a la ZQ620:

| Dispositivo | Cómo imprime | Instalar |
|---|---|---|
| Android / handheld | Zebra Browser Print (app de Zebra) → Bluetooth | App Browser Print |
| Computadora Windows/macOS | Chrome o Edge → Bluetooth LE (Web Bluetooth) | Nada |
| Computadora por USB | Zebra Browser Print → USB | Browser Print |
| iPhone / iPad | No compatible desde el navegador | — |

El diseño de la etiqueta (`app/Services/Etiquetas`) produce a la vez el ZPL y la vista previa SVG a escala real. Medidas en `config/etiquetas.php` (`ZEBRA_*` en `.env`).

Arquitectura, compatibilidad verificada, instalación por dispositivo y lista de pruebas: **[docs/impresion-zebra-zq620.md](docs/impresion-zebra-zq620.md)**. Los errores técnicos de impresión quedan en `storage/logs/impresion-*.log`.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
