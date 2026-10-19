# Pruebas de JavaScript del handheld

Prueban `public/js/anden.js` (cola de carga/recepción) y `public/js/impresion.js`
(Bluetooth e impresión) en un navegador simulado con [jsdom](https://github.com/jsdom/jsdom).
jsdom **no** es dependencia del proyecto; se instala aparte, solo para correrlas:

```bash
mkdir -p /tmp/jsprueba && cd /tmp/jsprueba && npm init -y && npm install jsdom@24.1.3
cp <proyecto>/tests/js/*.mjs .
node anden.test.mjs <proyecto>/public/js/anden.js
node impresion.test.mjs <proyecto>/public/js/impresion.js
node gatillo.test.mjs <proyecto>/public/js/escaneo.js
node validacion.test.mjs <proyecto>/public/js/escaneo.js <proyecto>/public/js/inventario.js
```

Simulan red caída, sesión expirada, recarga de la página, lecturas dobles del
gatillo, una impresora BLE que no responde, cancelar a media transmisión y
Browser Print. No sustituyen la prueba en el handheld y la ZQ620 reales
(ver `docs/flujo-carga-descarga.md`).
