# Análisis y planificación: OCR de etiquetas con Gemini en Laravel

Actúa como un **Senior Software Developer especializado en Laravel, integración de Gemini API y OCR de etiquetas industriales**.

## Objetivo

Implementar posteriormente una función que permita tomar o adjuntar una fotografía de la etiqueta de un producto desde el WMS y utilizar Gemini para extraer con precisión:

- **Modelo o SKU**
- **Número de serie (N/S)**

## Archivos existentes

**Creados:**
- `app/Http/Controllers/OcrController.php`
- `app/Services/GeminiOcrService.php`

**Modificados:**
- `routes/api.php`
- `config/services.php`
- `.env`

**Frontend con indicios de la función:**
- `resources/views/wms/index.blade.php`

Actualmente existe un botón deshabilitado para `Foto del N/S`, identificado mediante las clases `captura-opcion is-pendiente` y los atributos `disabled` y `aria-disabled="true"`.

## Instrucciones de análisis

1. Inspecciona los archivos indicados y revisa sus comentarios, TODO, pendientes y decisiones de diseño existentes.
2. Analiza la estructura actual del proyecto, las rutas, la autenticación, los permisos, las validaciones y el flujo de escaneo del WMS.
3. Identifica qué partes de la integración con Gemini ya funcionan, cuáles están incompletas y qué componentes pueden reutilizarse. No dupliques funcionalidades existentes.
4. Determina cómo integrar la captura o carga de fotografías desde el frontend, considerando el uso en computadoras, celulares y handhelds, sin interrumpir el flujo habitual de escaneo.
5. Define cómo enviar la imagen a Laravel, procesarla con Gemini y devolver los datos extraídos al frontend.
6. Diseña validaciones para evitar confundir el modelo o SKU con el número de serie. No inventes valores ni completes caracteres ilegibles; contempla resultados incompletos, errores de lectura y revisión manual.
7. Evalúa la seguridad de la API key, la validación del archivo, los límites de tamaño, los errores de Gemini, el consumo de la API y la prevención de registros duplicados.
8. Determina cómo integrar los resultados con el flujo actual de inventario, sin alterar reglas de negocio ajenas a esta función.

## Entregable

Presenta un plan técnico breve y concreto que incluya:

1. **Diagnóstico actual:** componentes existentes, funcionalidades implementadas, pendientes y hallazgos relevantes.
2. **Flujo propuesto:** desde la captura de la fotografía hasta la validación y presentación de los datos extraídos.
3. **Archivos involucrados:** cuáles se modificarían, cuáles nuevos serían necesarios y la responsabilidad de cada uno.
4. **Validaciones y manejo de errores:** cómo garantizar que los datos extraídos sean confiables y qué hacer cuando no puedan identificarse.
5. **Fases de implementación y pruebas:** pasos ordenados, incluyendo pruebas en escritorio, celular y handheld.
6. **Riesgos y decisiones pendientes:** únicamente los puntos que requieran atención o definición antes de programar.

Para cada cambio propuesto, explica brevemente su propósito. Distingue los hallazgos confirmados de las hipótesis que deban verificarse.

## Restricciones obligatorias

- **Esta fase es exclusivamente de análisis y planificación.**
- No modifiques, crees, elimines ni sobrescribas archivos.
- No ejecutes migraciones ni cambies configuraciones.
- No implementes código ni comiences a resolver los pendientes.
- No asumas que una función existe solo porque hay comentarios o elementos visuales.
- No propongas reestructuraciones innecesarias ni agregues dependencias sin justificar su necesidad.
- Mantén el alcance limitado al OCR de modelo/SKU y número de serie.

Entrega el plan basándote en el código real y sus comentarios. **Al finalizar, detente y espera mi aprobación explícita antes de realizar cualquier cambio.**