
(function () {
  'use strict';

  const btn = document.getElementById('btnImprimir');
  if (!btn) return;

  const $ = id => document.getElementById(id);
  const estado = $('impresionEstado');
  const panelVerificar = $('impresionVerificar');
  const btnBuscar = $('btnBuscar');
  const btnOlvidar = $('btnOlvidar');
  const textoBtn = btn.querySelector('[data-texto]');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const uuid = btn.dataset.uuid;

  const MSG = {
    buscando: 'Buscando impresora...',
    imprimiendo: 'Imprimiendo...',
    sinImpresora: 'No se encontró una impresora Zebra disponible. Verifica la conexión Bluetooth e inténtalo nuevamente.',
    sinSeleccion: 'No se seleccionó ninguna impresora. Presiona "Imprimir etiqueta" y elige tu Zebra de la lista.',
    conexion: 'No se pudo conectar con la impresora. Verifica que esté encendida, con Bluetooth activo y cerca de este equipo.',
    envio: 'La impresora no recibió la etiqueta. Verifica que esté encendida y cerca, e inténtalo nuevamente.',
    servidor: 'No se pudo preparar la etiqueta. Revisa tu conexión a internet e inténtalo nuevamente.',
    ios: 'Este dispositivo no permite imprimir desde el navegador. Usa una computadora o un equipo Android.',
    registrada: 'Listo. La impresión quedó registrada; ya puedes guardar la tarima.',
    noRegistrada: 'No se pudo registrar la impresión. Revisa tu conexión e inténtalo nuevamente.',
    olvidada: 'Listo. La próxima impresión te pedirá elegir la impresora Bluetooth.',
  };


  function mostrar(texto, tipo = 'info') {
    estado.textContent = texto;
    estado.dataset.tipo = tipo;
  }

  function ocupado(si) {
    btn.disabled = si;
    btn.classList.toggle('is-loading', si);
    btn.setAttribute('aria-busy', si ? 'true' : 'false');
    textoBtn.textContent = si ? 'Imprimiendo...' : 'Imprimir etiqueta';
    btnBuscar.disabled = si;
  }

  function conTiempo(promesa, ms) {
    let reloj;
    const limite = new Promise((_, rechazar) => {
      reloj = setTimeout(() => rechazar(Object.assign(new Error(`Sin respuesta en ${ms} ms`), { name: 'TimeoutError' })), ms);
    });
    return Promise.race([promesa, limite]).finally(() => clearTimeout(reloj));
  }

  const almacen = {
    leer(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    guardar(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* sin almacenamiento */ } },
    borrar(k) { try { localStorage.removeItem(k); } catch (e) { /* sin almacenamiento */ } },
  };


  function registrarFalla(metodo, etapa, error) {
    console.warn('[impresion]', metodo, etapa, error);
    try {
      fetch(btn.dataset.diagnostico, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({
          metodo, etapa, tarima: uuid,
          error: error ? String(error.name || 'Error').slice(0, 120) : null,
          detalle: error ? String(error.message || error).slice(0, 500) : null,
        }),
      }).catch(() => {});
    } catch (e) { /* el diagnóstico nunca debe romper la impresión */ }
  }

  function esIos() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent)
      || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }


  const BrowserPrint = {
    base: location.protocol === 'https:' ? 'https://localhost:9101/' : 'http://localhost:9100/',
    CONEXIONES: { bluetooth: 'Bluetooth', usb: 'USB', network: 'red', driver: 'controlador' },

    async impresora() {
      try {
        const r = await conTiempo(fetch(this.base + 'default?type=printer'), 2000);
        const texto = r.ok ? await r.text() : '';
        return texto ? JSON.parse(texto) : null;
      } catch (e) {
        return null;
      }
    },

    async enviar(dispositivo, zpl) {
      const r = await conTiempo(fetch(this.base + 'write', {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
        body: JSON.stringify({ device: dispositivo, data: zpl }),
      }), 20000);
      if (!r.ok) {
        throw Object.assign(new Error((await r.text().catch(() => '')) || `HTTP ${r.status}`), { name: 'BrowserPrintError' });
      }
    },

    conexion(d) { return this.CONEXIONES[d.connection] || d.connection || ''; },
  };


  const Ble = {
    SERVICIO: '38eb4a80-c570-11e3-9507-0002a5d5c51b',
    ESCRITURA: '38eb4a82-c570-11e3-9507-0002a5d5c51b',
    TROZO: 180,
    CLAVE: 'zebra-ble-id',
    dispositivo: null,
    caracteristica: null,

    soportado() { return window.isSecureContext && 'bluetooth' in navigator; },

    async recordado() {
      if (this.dispositivo) return this.dispositivo;
      const id = almacen.leer(this.CLAVE);
      if (!id || typeof navigator.bluetooth.getDevices !== 'function') return null;
      const lista = await navigator.bluetooth.getDevices();
      this.usar(lista.find(d => d.id === id) || null);
      return this.dispositivo;
    },

    async elegir() {
      const d = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: [this.SERVICIO] });
      this.usar(d);
      almacen.guardar(this.CLAVE, d.id);
      return d;
    },

    usar(d) {
      this.dispositivo = d;
      this.caracteristica = null;
      d?.addEventListener('gattserverdisconnected', () => { this.caracteristica = null; });
    },

    async conectar() {
      if (this.caracteristica && this.dispositivo.gatt.connected) return;
      const servidor = await conTiempo(this.dispositivo.gatt.connect(), 10000);
      const servicio = await servidor.getPrimaryService(this.SERVICIO);
      this.caracteristica = await servicio.getCharacteristic(this.ESCRITURA);
    },

    async enviar(zpl) {
      try {
        await this.conectar();
      } catch (e) {
        this.caracteristica = null;
        try { this.dispositivo.gatt.disconnect(); } catch (x) { /* ya desconectada */ }
        await this.conectar();
      }
      const bytes = new TextEncoder().encode(zpl);
      for (let i = 0; i < bytes.length; i += this.TROZO) {
        const trozo = bytes.slice(i, i + this.TROZO);
        if (this.caracteristica.writeValueWithResponse) await this.caracteristica.writeValueWithResponse(trozo);
        else await this.caracteristica.writeValue(trozo);
      }
    },

    async olvidar() {
      almacen.borrar(this.CLAVE);
      try {
        this.dispositivo?.gatt?.disconnect();
        await this.dispositivo?.forget?.();
      } catch (e) { /* nada que olvidar */ }
      this.dispositivo = null;
      this.caracteristica = null;
    },
  };


  async function detectar() {
    const bp = await BrowserPrint.impresora();
    if (bp) return { metodo: 'browserprint', nombre: bp.name, conexion: BrowserPrint.conexion(bp), dispositivo: bp };

    if (!Ble.soportado()) return null;

    const d = (await Ble.recordado()) || (await Ble.elegir());
    return { metodo: 'bluetooth', nombre: d.name || 'Zebra', conexion: 'Bluetooth', dispositivo: d };
  }

  function mensajeDeDeteccion(error) {
    if (error?.name === 'NotFoundError' && /cancel/i.test(error.message)) return ['aviso', MSG.sinSeleccion];
    return ['error', MSG.sinImpresora];
  }

  async function obtenerZpl() {
    const r = await conTiempo(fetch(btn.dataset.zpl, { credentials: 'same-origin' }), 15000);
    if (!r.ok) throw Object.assign(new Error(`HTTP ${r.status}`), { name: 'ServidorError' });
    return r.text();
  }

  async function imprimir() {
    if (esIos() && !Ble.soportado()) {
      mostrar(MSG.ios, 'error');
      registrarFalla('ninguno', 'ios-sin-bluetooth', null);
      return;
    }

    ocupado(true);
    panelVerificar.hidden = true;
    mostrar(MSG.buscando);

    let destino;
    try {
      destino = await detectar();
    } catch (e) {
      ocupado(false);
      const [tipo, texto] = mensajeDeDeteccion(e);
      mostrar(texto, tipo);
      if (tipo === 'error') registrarFalla('bluetooth', 'seleccion', e);
      return;
    }

    if (!destino) {
      ocupado(false);
      mostrar(MSG.sinImpresora, 'error');
      registrarFalla('ninguno', 'deteccion', null);
      return;
    }
    btnOlvidar.hidden = destino.metodo !== 'bluetooth';
    mostrar(MSG.imprimiendo);

    let zpl;
    try {
      zpl = await obtenerZpl();
    } catch (e) {
      ocupado(false);
      mostrar(MSG.servidor, 'error');
      registrarFalla(destino.metodo, 'zpl', e);
      return;
    }

    try {
      if (destino.metodo === 'browserprint') await BrowserPrint.enviar(destino.dispositivo, zpl);
      else await conTiempo(Ble.enviar(zpl), 30000);

      mostrar(`Etiqueta enviada a ${destino.nombre}. Revisa que se haya impreso.`, 'ok');
      pedirVerificacion();
    } catch (e) {
      mostrar(destino.metodo === 'bluetooth' ? MSG.conexion : MSG.envio, 'error');
      registrarFalla(destino.metodo, 'envio', e);
    } finally {
      ocupado(false);
    }
  }

  function pedirVerificacion() {
    panelVerificar.hidden = false;
    $('btnConfirmar').focus();
  }

  async function registrarImpresion() {
    const r = await fetch(btn.dataset.impresa, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const datos = await r.json().catch(() => null);
    if (!r.ok || !datos?.impresa) throw Object.assign(new Error(`HTTP ${r.status}`), { name: 'RegistroError' });

    try { sessionStorage.setItem('tarima-impresa:' + uuid, '1'); } catch (e) { /* sin almacenamiento */ }
    if ('BroadcastChannel' in window) {
      const canal = new BroadcastChannel('movingupper-tarimas');
      canal.postMessage({ tipo: 'impresa', uuid });
      canal.close();
    }
  }

  $('btnConfirmar').addEventListener('click', async () => {
    const boton = $('btnConfirmar');
    boton.disabled = true;
    try {
      await registrarImpresion();
      panelVerificar.hidden = true;
      mostrar(MSG.registrada, 'ok');
    } catch (e) {
      mostrar(MSG.noRegistrada, 'error');
      registrarFalla('ninguno', 'registro', e);
    } finally {
      boton.disabled = false;
    }
  });

  $('btnReintentar').addEventListener('click', () => {
    panelVerificar.hidden = true;
    imprimir();
  });

  btn.addEventListener('click', imprimir);

  btnBuscar.addEventListener('click', async () => {
    btnBuscar.disabled = true;
    mostrar(MSG.buscando);
    try {
      const destino = await detectar();
      if (destino) {
        mostrar(`Impresora lista: ${destino.nombre}${destino.conexion ? ` (${destino.conexion})` : ''}.`, 'ok');
        btnOlvidar.hidden = destino.metodo !== 'bluetooth';
      } else {
        mostrar(esIos() ? MSG.ios : MSG.sinImpresora, 'error');
        registrarFalla('ninguno', 'busqueda', null);
      }
    } catch (e) {
      const [tipo, texto] = mensajeDeDeteccion(e);
      mostrar(texto, tipo);
      if (tipo === 'error') registrarFalla('bluetooth', 'busqueda', e);
    } finally {
      btnBuscar.disabled = false;
    }
  });

  btnOlvidar.addEventListener('click', async () => {
    await Ble.olvidar();
    btnOlvidar.hidden = true;
    mostrar(MSG.olvidada);
  });

  $('btnNavegador').addEventListener('click', () => {
    panelVerificar.hidden = true;
    window.print();
  });
  window.addEventListener('afterprint', () => {
    mostrar('Si imprimiste la etiqueta, confirma que salió bien.', 'info');
    pedirVerificacion();
  });

  window.addEventListener('pagehide', () => {
    try { Ble.dispositivo?.gatt?.disconnect(); } catch (e) { /* ya desconectada */ }
  });

  if (esIos() && !Ble.soportado()) mostrar(MSG.ios, 'aviso');
})();
