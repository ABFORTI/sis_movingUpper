# Impresión de etiquetas en Zebra ZQ620

Etiqueta física **horizontal de 75 × 50 mm** (75 mm de ancho, 50 mm de alto) impresa por **Bluetooth** desde computadora, celular Android o handheld, con la aplicación alojada en **Hostinger**.

---

## 1. Diagnóstico (cómo funcionaba antes)

| Pieza | Antes |
|---|---|
| Diseño | Dos diseños distintos: ZPL para 4"×6" (812×1218 dots) en `EtiquetaQrZpl` / `EtiquetaTablaZpl`, y una tarjeta HTML/CSS aparte para la vista previa. Podían no coincidir. |
| QR | URL firmada `https://<dominio>/t/{uuid}?signature=…` (`TarimaService::urlDetalle`, ruta `tarimas.publico`). |
| Envío | `zebra.js` enviaba el ZPL a **Zebra Browser Print** (`https://localhost:9101`). Sin alternativa si Browser Print no estaba. |
| Bluetooth en PC | El README decía que Browser Print en Windows usaba Bluetooth: **era incorrecto** (ver §2). |
| Errores | Se mostraban mensajes técnicos al usuario y no se registraban. |

Datos: tarimas en `tarimas`, SKU en `registros`, números de serie en `numeros_serie`. El SKU y la N/S se capturan en formularios web; el handheld los escribe como teclado.

---

## 2. Restricciones verificadas

### Zebra ZQ620 (hoja técnica oficial ZQ600 Series)

| Característica | Valor |
|---|---|
| Resolución | **203 dpi (8 dots/mm)** |
| Ancho máximo de impresión | 72 mm |
| Ancho de papel | 25 mm a 79 mm → **75 mm es compatible** |
| Largo de impresión | 12.7 mm a 813 mm → 50 mm es compatible |
| Sensores | gap (etiqueta troquelada) y marca negra |
| Lenguajes | **CPCL, EPL y ZPL** |
| Bluetooth | **4.2 dual: Classic y Low Energy (BLE)**; certificado "Made for iPhone/iPad" |
| Otras conexiones | Wi-Fi 802.11ac (según configuración), USB 2.0 |

75 mm × 8 = **600 dots** de ancho de papel, pero el cabezal solo imprime **72 mm = 576 dots** (centrados: quedan ~1.5 mm sin imprimir en cada lado). 50 mm × 8 = **400 dots** de largo.

### Bluetooth del sistema operativo ≠ Bluetooth del navegador

- **Emparejar** la impresora en Android/Windows (o con *Zebra Printer Setup Utility*) crea un enlace que usan **aplicaciones nativas**. Una página web **no puede usar ese enlace**.
- JavaScript solo tiene la **Web Bluetooth API**, que habla con dispositivos **BLE** (no con Bluetooth Classic/SPP), exige **HTTPS** y que el usuario elija el dispositivo en un selector del navegador.
- Por eso, que el handheld imprima desde *Printer Setup Utility* demuestra que el hardware funciona, **no** que la web pueda imprimir por ese camino.

```text
Handheld ── Bluetooth (enlace del SO) ── ZQ620    ← lo usan apps nativas (Printer Setup Utility, Browser Print)
Página web ── ✗ no tiene acceso a ese enlace
Página web ── Web Bluetooth (BLE, con selector) ── ZQ620   ← camino propio del navegador
```

### Web Bluetooth por navegador (estado oficial del grupo W3C/Chrome)

| Plataforma | Soporte |
|---|---|
| Chrome / Edge en **Windows 10 (1703+)** | Sí |
| Chrome en **macOS / ChromeOS** | Sí |
| Chrome en **Android** | Sí |
| Chrome en Linux | Solo con bandera experimental (no usar) |
| **Safari y cualquier navegador en iPhone/iPad** | **No** (WebKit no lo implementa) |
| Firefox | No |

La ZQ620 expone por BLE el **Zebra Bluetooth LE Parser Service** (`38eb4a80-c570-11e3-9507-0002a5d5c51b`); se escribe ZPL en la característica `38eb4a82-…`, en bloques de hasta min(MTU, 512) bytes. BLE viene habilitado de fábrica (`bluetooth.le.controller_mode = both`).

### Zebra Browser Print (guías oficiales)

| Plataforma | Conexiones |
|---|---|
| Windows / macOS | **USB y red**. **No Bluetooth.** |
| Android 7+ con Chrome 75+ | **Red y Bluetooth**. La guía lista explícitamente la **ZQ620**. Solo ZPL II. |
| iOS | No existe |

### Zebra PrintConnect

App de Android que recibe trabajos mediante **Intents de Android** desde **apps nativas**. Una página web no puede enviarle esos Intents: **no sirve para esta aplicación web** sin construir una app Android.

---

## 3. Comparación de opciones

| Opción | PC | Android | iPhone | Handheld Android | Bluetooth | Requiere instalación | Complejidad |
|---|---|---|---|---|---|---|---|
| **A.** Diálogo de impresión del navegador | Sí, con controlador ZDesigner | Limitado (servicio de impresión del SO) | Sin controlador Zebra | Limitado | Solo a través del controlador del SO | Controlador de Windows | Baja, pero el resultado es una imagen rasterizada |
| **B.** ZPL + servicio local propio | Sí | Sí | No | Sí | Depende del servicio | Servicio a la medida en cada equipo | Alta: desarrollarlo, firmarlo, actualizarlo y asegurarlo |
| **C.** Zebra Browser Print | Sí (USB/red) | **Sí, Bluetooth** | No | **Sí, Bluetooth** | Solo Android | Browser Print | Baja: oficial de Zebra |
| **D.** Zebra PrintConnect | No | Solo desde apps nativas | No | Solo desde apps nativas | Sí | PrintConnect + app propia | Alta: exige app nativa |
| **E.** App Android propia (SDK Link-OS) | No | Sí | Otra app iOS aparte | Sí | Sí | App propia | Alta: desarrollo y distribución móvil |
| **F.** Web Bluetooth | **Sí (Chrome/Edge)** | Sí (Chrome) | **No** | Sí (Chrome) | **Sí, BLE** | Nada | Media: API experimental |

**A**: imprime una imagen (SVG rasterizado por el SO). Funciona como respaldo, pero la nitidez del QR y del código de barras depende del controlador. No es un envío directo de ZPL.
**B**: reproduce lo que Browser Print ya resuelve (origen, certificados, descubrimiento) y suma mantenimiento propio.
**D/E**: correctas si se construye una app móvil, lo cual está fuera de una aplicación web.

### Arquitectura elegida: C + F, detectadas automáticamente

1. **Browser Print** si responde en el equipo: Android/handheld por Bluetooth, o PC por USB.
2. Si no, **Web Bluetooth**: PC con Chrome/Edge, o Android con Chrome, directo a la ZQ620 por BLE.
3. Si ninguno está disponible: *"No se encontró una impresora Zebra disponible. Verifica la conexión Bluetooth e inténtalo nuevamente."*
4. Respaldo manual: diálogo del navegador (A) o descarga del `.zpl`.

**iPhone/iPad no es viable desde la web.** Requeriría una app nativa iOS con el SDK de Zebra (la ZQ620 es MFi) o un navegador de terceros con Web Bluetooth, lo que no se recomienda para uso empresarial.

```text
                 ┌──────────────────────────────┐
                 │ Hostinger                    │
                 │ Laravel + PHP + MySQL        │
                 │ • genera ZPL (75×50 mm)      │
                 │ • URL firmada del QR         │
                 │ • registra impresión/errores │
                 └──────────────┬───────────────┘
                                │ HTTPS (sesión + CSRF)
                                ▼
          ┌───────────────────────────────────────────┐
          │ Navegador en PC / Android / Handheld      │
          │ impresion.js                               │
          └──────┬───────────────────────────┬────────┘
                 │ HTTPS localhost:9101       │ Web Bluetooth (BLE)
                 ▼                            │ Chrome / Edge
     ┌───────────────────────┐                │
     │ Zebra Browser Print   │                │
     │ (app local de Zebra)  │                │
     └──────────┬────────────┘                │
                │ Bluetooth (Android)         │
                │ USB (Windows/macOS)         │
                ▼                             ▼
                 ┌──────────────────────────────┐
                 │ Zebra ZQ620 — ZPL II         │
                 └──────────────────────────────┘
```

### Componente local: Zebra Browser Print

| | |
|---|---|
| Qué es | Servicio/app oficial de Zebra que expone las impresoras del equipo a páginas web autorizadas. |
| Dónde se instala | En cada Android/handheld que imprima por Bluetooth, y en PCs que impriman por USB. |
| Sistemas | Android 7+ (Chrome 75+); Windows y macOS. |
| Comunicación con la web | El navegador llama a `https://localhost:9101` (`/default`, `/available`, `/write`). |
| Comunicación con la Zebra | Android: Bluetooth o red. Windows/macOS: USB o red. |
| Cómo recibe el trabajo | El navegador descarga el ZPL de Hostinger (ruta autenticada) y lo envía a `/write` junto con la impresora predeterminada. |
| Autenticación | Cada sitio debe **aceptarse** en Browser Print (lista *Accepted Hosts*). Un sitio no aceptado no puede imprimir. |
| Errores | `impresion.js` muestra un mensaje claro y manda el detalle técnico a `POST /impresion/diagnostico` → `storage/logs/impresion-AAAA-MM-DD.log`. |
| Internet | Solo para obtener el ZPL del servidor. El envío a la impresora es local. |

Web Bluetooth **no instala nada**: el navegador conecta por BLE después de que el usuario elige la impresora en el selector.

---

## 4. Diseño de la etiqueta (horizontal, 75 × 50 mm, 600 × 400 dots)

Un solo diseño en dots (`app/Services/Etiquetas`) genera el **ZPL** (`ZplRenderer`) y la **vista previa SVG a tamaño físico** (`SvgRenderer`). Las coordenadas son de la etiqueta completa (600 dots). El ZPL usa `^PW576` y resta a cada X la franja que el cabezal no alcanza (12 dots), así lo que se ve en la vista previa cae exactamente en el papel.

Márgenes: 2 mm arriba y abajo; en horizontal, 2 mm **desde el borde del cabezal** (3.5 mm desde el borde del papel). Área de contenido: **68 × 46 mm**.

**Elementos comunes** (`DisenoBase`)

| mm desde arriba | Elemento | Tamaño |
|---|---|---|
| 2 | Logo **Upper Logistics** (izquierda) y **Euroquip** (derecha) | máx. 21 × 4.25 mm, sin deformar |
| 7 | línea divisoria | 0.375 mm (3 dots) |
| 43 | Code128 `TAR-12|20260930` a la izquierda + texto legible a la derecha | módulo 0.25 mm, 5 mm de alto |

Los logos (`public/images/upper_logo1.png` y `Euroquip4.png`) se convierten en `LogoMonocromo`: se recorta el margen blanco del archivo, se escalan con **una sola escala** (proporción intacta) y se pasan a 1 bit. La impresora térmica no tiene color: todo trazo de color (rojo, naranja, azul, verde) se imprime negro y el fondo queda sin imprimir. En el ZPL van como `^GFA`; la conversión se guarda en caché y se regenera sola si cambia el archivo.

**Tarima de SKU padre (QR)** — `EtiquetaPadreQr`: datos a la izquierda (44 mm), QR a la derecha (34.5 mm con su zona blanca).

| mm desde arriba | Columna izquierda | Tamaño |
|---|---|---|
| 7.75 | bloque negro con `TARIMA 12` en blanco | 5 mm |
| 13.5 | `SKU` + valor (uno grande; dos en una línea; 3+ → primero y "+ N SKUs más") | 5.5 mm, baja hasta 2.5 mm si es largo |
| ~21.5 | descripción | 2.25 mm |
| ~24.75 | `DESTINO` / `FECHA` | rótulo 2 mm + valor 2.75 mm |
| ~30.75 | `N/S · DETALLE EN EL QR`: primera N/S y cuántas más (`SN-000124 +33`) | rótulo 2 mm + valor 2.75 mm |
| ~36.75 | `SKUS PADRE` / `TOTAL N/S` | rótulo 2 mm + valor 2.75 mm |

**QR**: corrección M, zona blanca de 3 módulos, centrado en su columna con ≈ 5 mm de aire alrededor. El comando `^BQ` de Zebra imprime el QR unos puntos más abajo que su `^FO`; el ZPL lo sube `ZEBRA_AJUSTE_QR_MM` (1.5 mm por defecto) para que quede donde lo muestra la vista previa. Módulo de **0.5 mm (4 dots) → QR de ≈ 24.5 mm** (26.5 mm si la URL pasa de ~150 caracteres).

**Tarima de SKUs sueltos o mixta (listado)** — `EtiquetaListado`: bloque `TARIMA 12 (1/2)` y a su derecha `DESTINO`, `FECHA`, `PIEZAS`; columnas `# | SKU | N/S | DESCRIPCIÓN`, **9 renglones por etiqueta** (texto 2.5 mm, renglón 2.875 mm), paginado `(1/N)` y el mismo pie.

En horizontal vuelve a caber la **descripción** en el listado (recortada con "…" si es larga; completa en Excel y en el historial). A cambio caben 9 renglones por etiqueta en lugar de 13.

El QR conserva **exactamente** el mismo destino (`tarimas.publico`, URL firmada).

---

## 5. Configuración de la impresora (una sola vez)

Con **Zebra Printer Setup Utility** (Android/iOS) o **Zebra Setup Utilities** (Windows):

1. Cargar el rollo de **75 mm de ancho** con etiquetas de **50 mm de largo**, centrado entre las guías, y **calibrar el papel** (tipo *gap* para etiqueta troquelada).
2. Verificar que el lenguaje acepte **ZPL**: `device.languages` debe ser `zpl` o `hybrid_xml_zpl`. Si está en `line_print` (solo CPCL), cambiarlo:
   `! U1 setvar "device.languages" "zpl"`
3. Anotar el **nombre Bluetooth** de la impresora (*friendly name*). Aparece en la pantalla de la ZQ620, en Printer Setup Utility y en la etiqueta de configuración. Es el nombre que se verá en el selector.
4. Confirmar que BLE esté activo: `bluetooth.le.controller_mode` = `both` (valor de fábrica).
5. Si se usa marca negra o papel continuo, poner `ZEBRA_MEDIA=marca` o `ZEBRA_MEDIA=continuo` en `.env`.

---

## 6. Instalación por dispositivo

### Windows (Bluetooth, recomendado)

1. Windows 10 versión 1703 o posterior, con **Bluetooth 4.0+ (LE)** en el equipo.
2. Encender la ZQ620 con Bluetooth activo, cerca del equipo.
3. Abrir la aplicación en **Chrome o Edge** (https).
4. Etiqueta → **Imprimir etiqueta** → elegir la ZQ620 en el selector del navegador.
5. Confirmar que la etiqueta salió bien → **Sí, se imprimió bien**.

No hace falta emparejarla en *Configuración de Windows*: Web Bluetooth usa su propio selector. Por USB: instalar **Zebra Browser Print para Windows**, marcar la ZQ620 como predeterminada y aceptar el sitio.

### Android (celular)

**Opción recomendada — Browser Print:**
1. Instalar el APK oficial **Zebra Browser Print para Android** (Android 7+, Chrome 75+).
2. Abrir la app → *Discover Printers* (Bluetooth Discovery activado) → elegir la ZQ620 como **Default**.
3. En Chrome, abrir la aplicación → **Imprimir etiqueta** → **Permitir** cuando Browser Print pregunte por el sitio (queda en *Accepted Hosts*).
4. Si Chrome pide permiso para acceder a dispositivos locales o cercanos, permitirlo.

**Sin instalar nada:** Chrome en Android usa Web Bluetooth y abre el selector de la impresora al imprimir. Requiere permitir **Dispositivos cercanos / Ubicación** a Chrome.

### Handheld Android (Zebra TC u otros)

1. Igual que Android (Browser Print recomendado; la guía de Zebra lista TC51/TC52/TC57).
2. **Escaneo:** configurar el perfil de **DataWedge** (o el equivalente del fabricante) para Chrome con *Keystroke output* y sufijo **Enter**. Los campos SKU y N/S ya tienen el foco y se envían con Enter.
3. Si *Printer Setup Utility* u otra app tiene abierta la conexión Bluetooth con la impresora, **cerrarla** antes de imprimir.

### iPhone / iPad

**No compatible desde la web.** Safari y todos los navegadores de iOS carecen de Web Bluetooth, y Browser Print no existe para iOS. La aplicación lo detecta y muestra: *"Este dispositivo no permite imprimir desde el navegador. Usa una computadora o un equipo Android."* Una alternativa requeriría una app nativa iOS con el SDK de Zebra.

---

## 7. Seguridad

- El navegador **solo** envía el ZPL que genera el servidor para esa tarima (`GET /tarimas/{uuid}/etiqueta.zpl`, con sesión y la Policy de la tarima). No existe ningún endpoint que acepte ZPL del navegador.
- Todo texto variable va con `^FH` (hexadecimal): un SKU con `^`, `~` o `\` no puede inyectar comandos.
- **Browser Print** solo atiende sitios aceptados (*Accepted Hosts*) y escucha en `localhost`, no expuesto a la red.
- **Web Bluetooth** exige HTTPS y que el usuario elija la impresora; una página no puede conectarse en silencio.
- La marca "impresa" (que habilita *Guardar*) se registra **solo cuando el usuario confirma** que la etiqueta física salió bien.
- El endpoint de diagnóstico exige sesión, valida longitudes y tiene límite de 30 solicitudes por minuto.

---

## 8. Pruebas en campo

| # | Prueba | Resultado esperado |
|---|---|---|
| 1 | Imprimir tarima de SKU padre desde Windows + Chrome (BLE) | Selector → ZQ620 → etiqueta horizontal de 75×50 mm, sin cortes; logos a los lados |
| 2 | Lo mismo desde Android con Browser Print | Imprime sin selector, usando la impresora predeterminada |
| 3 | Desde handheld: escanear SKU y N/S con el gatillo, luego imprimir | Captura con Enter automático; etiqueta correcta |
| 4 | Medir la etiqueta impresa | 75 × 50 mm; nada a menos de 3.5 mm de los lados ni de 2 mm arriba/abajo |
| 5 | Escanear el QR con celular y con handheld | Abre `/t/{uuid}?signature=…` con el detalle de N/S |
| 6 | Leer el Code128 con el handheld | Lee `TAR-{n}|{AAAAMMDD}` |
| 7a | Comparar la posición del QR impresa con la vista previa | Si queda bajo, subir `ZEBRA_AJUSTE_QR_MM` (p. ej. 2); si queda alto, bajarlo |
| 7 | SKU de 30 caracteres | Se reduce la fuente o se recorta con "…"; nada sale del área |
| 8 | Tarima con 30 SKUs sueltos | 3 etiquetas `(1/3)…(3/3)`, 13 renglones cada una |
| 9 | Caracteres especiales en SKU/N/S (`Ñ`, `é`, `^`, `~`, `\`) | Se imprimen literalmente; no se corta el formato |
| 10 | Imprimir dos veces seguidas por BLE | La segunda no vuelve a pedir la impresora (misma página) |
| 11 | Apagar la impresora y presionar Imprimir | Mensaje: no se pudo conectar…; registro en `impresion-*.log` |
| 12 | Alejarse fuera de alcance durante el envío | Mensaje de conexión; al volver, **Imprimir** reconecta |
| 13 | Encender de nuevo y reintentar | Imprime sin recargar la página |
| 14 | Cancelar el selector Bluetooth | "No se seleccionó ninguna impresora" |
| 15 | Browser Print cerrado en Android | Cae a Web Bluetooth (selector); si no hay BLE, mensaje de impresora no encontrada |
| 16 | Abrir la etiqueta en iPhone | Mensaje de dispositivo no compatible |
| 17 | Responder "No, imprimir de nuevo" | No se registra la impresión; *Guardar* sigue deshabilitado |
| 18 | Responder "Sí, se imprimió bien" | *Guardar* se habilita en la vista de escaneo |
| 19 | Papel agotado | La etiqueta no sale; el usuario responde "No" y no se registra |
| 20 | Otro sitio web intenta imprimir con Browser Print | Browser Print lo bloquea hasta que se acepte |

Pruebas automatizadas: `php artisan test` (`tests/Feature/EtiquetaZq620Test.php` valida 400×600 dots, que ningún elemento salga del área, el tamaño del QR, la paginación, el escape de caracteres y el diagnóstico).

---

## Fuentes

- [Zebra ZQ600 Series — hoja técnica](https://www.zebra.com/content/dam/zebra_dam/en/spec-sheets/zq610-zq620-zq630-spec-sheet-en-us.pdf)
- [Zebra Browser Print — soporte](https://www.zebra.com/us/en/support-downloads/software/printer-software/browser-print.html)
- [Browser Print Android User Guide](https://www.zebra.com/content/dam/zebra_new_ia/en-us/solutions-verticals/product/Software/Printer%20Software/Link-OS/browser-print/zebra-browser-print-user-guide-v1-3-android-en-us.pdf)
- [Link-OS Bluetooth Low Energy AppNote](https://www.zebra.com/content/dam/support-dam/en/documentation/unrestricted/application-notes/AppNote-BlueToothLE-v4.pdf)
- [Zebra Developer Portal — ZQ620 y Web Bluetooth en Chrome](https://developer.zebra.com/content/zq620-do-not-bluetooth-connection-chrome-javascript)
- [Web Bluetooth — estado de implementación](https://github.com/WebBluetoothCG/web-bluetooth/blob/main/implementation-status.md)
- [MDN — Web Bluetooth API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Bluetooth_API)
- [Zebra PrintConnect](https://www.zebra.com/us/en/products/software/barcode-printers/link-os/print-connect.html)
