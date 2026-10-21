# Impresión de etiquetas en Zebra ZQ620

Etiqueta física **horizontal de 75 × 50 mm** (75 mm de ancho, 50 mm de alto) impresa por **Bluetooth** desde computadora, celular Android o handheld, con la aplicación alojada en **Hostinger**.



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

**Etiqueta por pieza** (`EtiquetaPieza`, desde 2026-10-14). Cada pieza escaneada lleva **su propia** etiqueta; ninguna representa al embarque completo. Solo tiene tres datos:

| mm desde arriba | Elemento | Tamaño |
|---|---|---|
| 2 | rótulo `MODELO` | 2.5 mm |
| 4.75 | modelo de la pieza (del renglón del inventario; si no tiene, el del SKU; si tampoco, el SKU) | hasta 8 mm, baja hasta 3.5 mm o se recorta con "…" si es largo |
| 13.5 | rótulo `EMBARQUE` | 2.5 mm |
| 16.25 | número de embarque completo, `MUD-0001 · E-004` (el mismo en todas las piezas del embarque) | hasta 7 mm |
| 25 | **Code 128 de la N/S**, centrado, con 2.5 mm de zona blanca a cada lado | 17 mm de alto, módulo 0.25 mm (2 dots); 0.125 mm si la N/S es muy larga |
| 42.75 | la misma N/S en texto (línea de interpretación), por si hay que escribirla a mano | 2–3.5 mm |

- **El código de barras codifica la N/S exacta**, tal como quedó registrada: al leerlo con la handheld (DataWedge en modo teclado) se obtiene esa N/S, sin prefijos (`TAR-`), sin el SKU ni el número de embarque. Es la misma simbología **Code 128** (`^BC`, modo automático `A`) que la etiqueta anterior usaba en su pie, así que no cambia la configuración del escáner.
- `^`, `~` y `\` dentro de la N/S van con `^FH` (`E`, `E`, `C`): la impresora los decodifica y el código de barras sigue siendo la N/S exacta.
- Code 128 solo admite ASCII imprimible. Una N/S con otros caracteres (p. ej. `Ñ`) no se puede codificar fielmente: la etiqueta muestra la N/S en texto grande y la leyenda `N/S · SIN CÓDIGO DE BARRAS`.
- **Sin** logos, QR, fecha, destino, cantidad, descripción ni número de tarima. El tamaño (75 × 50 mm), la orientación (`^PON`), el tipo de papel, `^PW`/`^LL`/`^LT` y el mecanismo de envío (Browser Print o Web Bluetooth) no cambiaron.
- Varias piezas = varias etiquetas en un solo envío (`^XA…^XZ` por pieza). La URL del ZPL y la de confirmación llevan los ids de las piezas elegidas (`?piezas=12,15`), así se marca como impreso exactamente lo que salió.

**Qué piezas se imprimen.** `numeros_serie.etiqueta_impresa_at` marca las piezas que ya tienen etiqueta. La página de etiquetas prepara por omisión **solo las que faltan** (al seguir escaneando no se reimprimen las ya pegadas); también permite **todas** o **una sola** (si una etiqueta se dañó). El embarque se puede **guardar** cuando ninguna pieza con N/S está sin etiqueta. Si una pieza se mueve a otro embarque, su etiqueta (que trae el número anterior) vuelve a quedar pendiente y su embarque destino queda en «Reimprimir» hasta imprimirla.

Diseños anteriores retirados: `EtiquetaPadreQr` (tarima con QR) y `EtiquetaListado` (listado paginado). Las etiquetas de tarima ya pegadas siguen funcionando en el andén: `TAR-{id}|fecha`, el QR (`/t/{uuid}`) y `T-004` se reconocen. La consulta firmada `/t/{uuid}` sigue existiendo y ahora se abre desde **Historial → Ver piezas**.

---

## 5. Configuración de la impresora (una sola vez)

Lo más rápido: en la aplicación, **Etiqueta → Etiqueta de prueba y calibración** (`/impresion/prueba`) → elegir la impresora → **Calibrar papel de la impresora** → **Imprimir etiqueta de prueba**. Eso guarda en la ZQ620 el tamaño configurado (`^JUS`) y calibra el sensor (`~JC`).

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

- El navegador **solo** envía el ZPL que genera el servidor para las piezas de ese embarque (`GET /tarimas/{uuid}/etiqueta.zpl?piezas=…`, con sesión y la Policy del embarque; los ids de otro embarque se ignoran). No existe ningún endpoint que acepte ZPL del navegador.
- Todo texto variable va con `^FH` (hexadecimal): un SKU con `^`, `~` o `\` no puede inyectar comandos.
- **Browser Print** solo atiende sitios aceptados (*Accepted Hosts*) y escucha en `localhost`, no expuesto a la red.
- **Web Bluetooth** exige HTTPS y que el usuario elija la impresora; una página no puede conectarse en silencio.
- La marca "impresa" de cada pieza (que habilita *Guardar* cuando no falta ninguna) se registra **solo cuando el usuario confirma** que las etiquetas físicas salieron bien.
- El endpoint de diagnóstico exige sesión, valida longitudes y tiene límite de 30 solicitudes por minuto.



## 8. Impresora registrada por MAC (2026-10-12)

Tabla `impresoras` (nombre, **MAC** única normalizada `AA:BB:CC:DD:EE:FF`, nombre Bluetooth, ubicación, activa), administrada en **Admin → Impresoras**. Cada equipo elige la suya en el panel de impresión y se recuerda en el navegador de ese equipo (`localStorage`).

| Camino | Cómo usa el registro |
|---|---|
| **Browser Print** (Bluetooth Clásico) | Consulta `available` y `default`, y busca la MAC en los datos de cada impresora (sin suponer el campo). Si la encuentra, imprime ahí aunque la predeterminada sea otra. Si Browser Print no la lista, intenta enviar a un dispositivo armado con la MAC; si lo rechaza, pide descubrirla en la app. |
| **Web Bluetooth** (BLE, Chrome) | Chrome no expone la MAC: el selector se filtra por el **nombre Bluetooth** registrado, así solo aparece esa impresora. |

**[EN EQUIPO]** Zebra no documenta públicamente en qué campo de Browser Print viene la MAC de una impresora Bluetooth (se espera en `uid`). El botón **«Ver impresoras que detecta Browser Print»** muestra los datos reales y los manda a `storage/logs/impresion-*.log` (etapa `inventario`), para confirmarlo en el primer uso.

Según el foro de desarrolladores de Zebra, Browser Print para Android solo usa Bluetooth Clásico; la impresora necesita `bluetooth.enable` y `bluetooth.discoverable` en `on`, y `bluetooth.le.controller_mode` en `both` o `classic`.

