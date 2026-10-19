/*
 * Impresión en Zebra (ZQ620) desde la página de la etiqueta o la de prueba.
 *
 * Dos caminos, en este orden:
 * 1. Zebra Browser Print (app oficial). La página le habla en localhost; la app
 *    usa el emparejamiento Bluetooth normal de Android y recuerda la impresora.
 * 2. Web Bluetooth (BLE) de Chrome, sin instalar nada. Pide elegir la impresora
 *    en el selector del navegador.
 *
 * Por qué está hecho así (causas de "la lista aparece y no deja elegir"):
 * - Chrome solo abre el selector de Bluetooth durante unos segundos después de
 *   un toque (activación de usuario). Antes se esperaba la búsqueda de Browser
 *   Print y getDevices() y luego se abría el selector: con red lenta el permiso
 *   ya había vencido. Ahora Browser Print se detecta al cargar la página y el
 *   selector se abre en el mismo toque, antes de cualquier espera.
 * - Una conexión GATT que no responde se quedaba pendiente aunque la pantalla
 *   dijera "tiempo agotado"; el siguiente intento se encolaba detrás y todo
 *   parecía congelado. Ahora, al vencer el tiempo o al cancelar, se llama a
 *   gatt.disconnect(), que aborta la conexión pendiente.
 * - Solo hay una operación a la vez (imprimir, elegir, calibrar) y siempre con
 *   progreso visible y botón Cancelar.
 * - Si un envío se corta a la mitad, la impresora queda con una etiqueta a
 *   medias en su memoria; el siguiente envío empieza con ~JA (borra el formato
 *   incompleto) para que no se mezclen.
 */
(function () {
  'use strict';

  const btn = document.getElementById('btnImprimir');
  if (!btn) return;

  const $ = id => document.getElementById(id);
  const estado = $('impresionEstado');
  const actual = $('impresoraActual');
  const panelVerificar = $('impresionVerificar');
  const panelProgreso = $('impresionProgreso');
  const barra = panelProgreso.querySelector('progress');
  const btnCancelar = $('btnCancelar');
  const btnElegir = $('btnElegir');
  const btnBuscar = $('btnBuscar');
  const btnOlvidar = $('btnOlvidar');
  const btnCalibrar = $('btnCalibrar');
  const btnNavegador = $('btnNavegador');
  const textoBtn = btn.querySelector('[data-texto]');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const uuid = btn.dataset.uuid || null;
  const esAndroid = /Android/i.test(navigator.userAgent);

  const MSG = {
    buscando: 'Buscando impresora…',
    conectando: nombre => `Conectando con ${nombre}…`,
    imprimiendo: 'Enviando la etiqueta…',
    sinImpresora: 'No hay impresora disponible. Elige la impresora de este equipo y abre Zebra Browser Print, o toca «Conectar por Bluetooth directo».',
    macDesconocida: (nombre, mac) => `Browser Print no reconoce la impresora ${nombre} (${mac}). En la app Browser Print toca «Discover Printers», espera a que aparezca esa MAC y vuelve a imprimir. Si no aparece: empareja la ZQ620 en Ajustes → Bluetooth de Android.`,
    sinSeleccion: 'No se eligió ninguna impresora. Vuelve a tocar el botón y elige tu Zebra de la lista.',
    sinBluetooth: 'El Bluetooth del equipo está apagado o no disponible. Actívalo e inténtalo de nuevo.',
    gesto: 'El navegador no abrió el selector. Toca el botón otra vez (el selector solo se abre justo después de tocar).',
    noSoportado: 'Este navegador no puede usar Bluetooth. Usa Chrome en Android o en computadora, o instala Zebra Browser Print.',
    conexion: 'No se pudo conectar con la impresora. Verifica que esté encendida, con Bluetooth activo y a menos de 5 m; luego reintenta.',
    tiempo: 'La impresora no respondió a tiempo. Apágala y enciéndela, espera 10 segundos y reintenta.',
    envio: 'La etiqueta no terminó de enviarse. Reintenta; si se repite, apaga y enciende la impresora.',
    cancelado: 'Cancelado. No se imprimió nada nuevo.',
    servidor: 'No se pudo preparar la etiqueta. Revisa tu conexión a internet e inténtalo de nuevo.',
    ios: 'Este dispositivo no permite imprimir desde el navegador. Usa una computadora o un equipo Android.',
    registrada: 'Listo. La impresión quedó registrada; ya puedes guardar el embarque.',
    faltan: n => `Listo. La impresión quedó registrada. Faltan ${n} ${n === 1 ? 'pieza' : 'piezas'} de este embarque sin etiqueta: imprímelas antes de guardarlo.`,
    noRegistrada: 'No se pudo registrar la impresión. Revisa tu conexión e inténtalo de nuevo.',
    olvidada: 'Listo. La próxima vez se te pedirá elegir la impresora Bluetooth.',
    calibrada: 'Se envió la calibración: la impresora avanzará una o dos etiquetas. Después imprime otra prueba.',
  };

  // ---------- Utilidades ----------
  function mostrar(texto, tipo = 'info') {
    estado.textContent = texto;
    estado.dataset.tipo = tipo;
  }

  function error(nombre, mensaje) {
    return Object.assign(new Error(mensaje || nombre), { name: nombre });
  }

  function conTiempo(promesa, ms, alVencer) {
    let reloj;
    const limite = new Promise((_, rechazar) => {
      reloj = setTimeout(() => {
        try { alVencer?.(); } catch (e) { /* nada que abortar */ }
        rechazar(error('TimeoutError', `Sin respuesta en ${ms} ms`));
      }, ms);
    });
    return Promise.race([promesa, limite]).finally(() => clearTimeout(reloj));
  }

  const almacen = {
    leer(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    guardar(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* sin almacenamiento */ } },
    borrar(k) { try { localStorage.removeItem(k); } catch (e) { /* sin almacenamiento */ } },
  };

  function registrarFalla(metodo, etapa, e) {
    console.warn('[impresion]', metodo, etapa, e);
    try {
      fetch(btn.dataset.diagnostico, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({
          metodo, etapa, tarima: uuid,
          error: e ? String(e.name || 'Error').slice(0, 120) : null,
          detalle: e ? String(e.message || e).slice(0, 500) : null,
        }),
      }).catch(() => {});
    } catch (x) { /* el diagnóstico nunca debe romper la impresión */ }
  }

  function esIos() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  // ---------- Una operación a la vez ----------
  let operacion = null;
  const botones = () => [btn, btnElegir, btnBuscar, btnOlvidar, btnCalibrar, btnNavegador].filter(Boolean);

  function ocupar(nombre, { cancelable = false } = {}) {
    operacion = nombre;
    botones().forEach(b => { b.disabled = true; });
    btn.classList.toggle('is-loading', nombre === 'imprimir');
    btn.setAttribute('aria-busy', String(nombre === 'imprimir'));
    if (nombre === 'imprimir') textoBtn.textContent = 'Imprimiendo…';
    panelProgreso.hidden = !cancelable;
    barra.removeAttribute('value'); // indeterminada hasta que haya avance
    btnCancelar.disabled = false;
  }

  function liberar() {
    operacion = null;
    botones().forEach(b => { b.disabled = false; });
    btn.classList.remove('is-loading');
    btn.setAttribute('aria-busy', 'false');
    textoBtn.textContent = btn.dataset.textoBase || 'Imprimir etiqueta';
    panelProgreso.hidden = true;
  }

  function avance(fraccion) {
    barra.value = Math.round(fraccion * 100);
  }

  // ---------- Impresora de este equipo (por MAC) ----------
  // La elige el operador de las registradas por un admin y se recuerda en el
  // equipo. Una página web no puede conectarse a una MAC: la MAC se usa para
  // encontrar la impresora entre las que ve Zebra Browser Print, que sí usa el
  // emparejamiento Bluetooth Clásico de Android.
  const selectorEquipo = $('impresoraEquipo');
  const CLAVE_EQUIPO = 'impresora-mac';

  function normalizarMac(texto) {
    const hex = String(texto || '').replace(/[^0-9a-f]/gi, '').toUpperCase();
    return hex.length === 12 ? hex.match(/../g).join(':') : null;
  }

  /** MAC que aparezca en el objeto de Browser Print (uid, address, nombre…), sin suponer el campo. */
  function macDe(dispositivo) {
    const m = JSON.stringify(dispositivo || {}).match(/(?<![0-9a-f])(?:[0-9a-f]{2}[:-]?){5}[0-9a-f]{2}(?![0-9a-f])/i);
    return m ? normalizarMac(m[0]) : null;
  }

  const equipo = {
    get mac() { return selectorEquipo?.value || null; },
    get opcion() { return selectorEquipo?.selectedOptions[0] || null; },
    get nombre() { return this.mac ? (this.opcion?.dataset.nombre || this.mac) : null; },
    get nombreBluetooth() { return this.mac ? (this.opcion?.dataset.nombreBluetooth || null) : null; },
  };

  if (selectorEquipo) {
    const guardada = almacen.leer(CLAVE_EQUIPO);
    const opciones = Array.from(selectorEquipo.options).filter(o => o.value);
    if (guardada && opciones.some(o => o.value === guardada)) selectorEquipo.value = guardada;
    else if (opciones.length === 1) selectorEquipo.value = opciones[0].value; // una sola registrada: esa
  }

  // ---------- Zebra Browser Print ----------
  const BrowserPrint = {
    base: location.protocol === 'https:' ? 'https://localhost:9101/' : 'http://localhost:9100/',
    CONEXIONES: { bluetooth: 'Bluetooth', usb: 'USB', network: 'red', driver: 'controlador' },
    activo: undefined,      // undefined = buscando · false = no responde · true = responde
    lista: [],              // impresoras que Browser Print conoce
    predeterminada: null,
    busqueda: null,

    async pedir(ruta) {
      const r = await conTiempo(fetch(this.base + ruta), 2500);
      const texto = r.ok ? await r.text() : '';
      return texto ? JSON.parse(texto) : null;
    },

    buscar() {
      this.activo = undefined;
      this.busqueda = (async () => {
        let respondio = false;
        const intentar = ruta => this.pedir(ruta).then(d => { respondio = true; return d; }).catch(() => null);
        const [disponibles, predeterminada] = await Promise.all([intentar('available'), intentar('default?type=printer')]);
        // "available" puede venir como lista o como { printer: [...], ... }.
        const todas = Array.isArray(disponibles) ? disponibles
          : Object.values(disponibles || {}).flat().filter(d => d && typeof d === 'object');
        this.lista = todas.filter(d => !d.deviceType || d.deviceType === 'printer');
        this.predeterminada = predeterminada && typeof predeterminada === 'object' ? predeterminada : null;
        this.activo = respondio;
        return this.activo;
      })();
      return this.busqueda;
    },

    /**
     * Dispositivo de Browser Print para imprimir. Con impresora del equipo, el
     * que tenga su MAC (exacto); si Browser Print no la lista, se intenta con
     * un dispositivo armado con la MAC (no exacto: puede rechazarlo).
     */
    destino() {
      if (!this.activo) return null;
      const conocidas = [...this.lista, this.predeterminada].filter(Boolean);
      if (equipo.mac) {
        const exacta = conocidas.find(d => macDe(d) === equipo.mac);
        if (exacta) return { dispositivo: exacta, exacto: true };
        return {
          dispositivo: { name: equipo.nombre, uid: equipo.mac, connection: 'bluetooth', deviceType: 'printer', manufacturer: 'Zebra Technologies' },
          exacto: false,
        };
      }
      return this.predeterminada ? { dispositivo: this.predeterminada, exacto: true } : null;
    },

    async enviar(dispositivo, zpl) {
      const r = await conTiempo(fetch(this.base + 'write', {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
        body: JSON.stringify({ device: dispositivo, data: zpl }),
      }), 20000);
      if (!r.ok) throw error('BrowserPrintError', (await r.text().catch(() => '')) || `HTTP ${r.status}`);
    },

    conexion(d) { return this.CONEXIONES[d?.connection] || d?.connection || ''; },
  };

  // ---------- Web Bluetooth (BLE) ----------
  const Ble = {
    // Servicio "Zebra Parser" de las impresoras Link-OS con BLE y su característica de escritura.
    SERVICIO: '38eb4a80-c570-11e3-9507-0002a5d5c51b',
    ESCRITURA: '38eb4a82-c570-11e3-9507-0002a5d5c51b',
    TROZO: 180,
    CLAVE: 'zebra-ble-id',
    dispositivo: null,
    caracteristica: null,
    cancelado: false,
    incompleto: false, // el último envío se cortó a la mitad

    soportado() { return window.isSecureContext && 'bluetooth' in navigator; },

    async recordado() {
      if (this.dispositivo) return this.dispositivo;
      const id = almacen.leer(this.CLAVE);
      if (!id || typeof navigator.bluetooth?.getDevices !== 'function') return null;
      try {
        const lista = await navigator.bluetooth.getDevices();
        this.usar(lista.find(d => d.id === id) || null);
      } catch (e) { /* sin permiso guardado */ }
      return this.dispositivo;
    },

    /** Abre el selector. Debe llamarse en el mismo toque, sin esperas antes. */
    elegir() {
      // Con nombre Bluetooth registrado, la lista muestra solo esa impresora
      // (Chrome no expone la MAC, así que no se puede filtrar por ella).
      const filtro = equipo.nombreBluetooth
        ? { filters: [{ name: equipo.nombreBluetooth }], optionalServices: [this.SERVICIO] }
        : { acceptAllDevices: true, optionalServices: [this.SERVICIO] };
      return navigator.bluetooth.requestDevice(filtro)
        .then(d => { this.usar(d); almacen.guardar(this.CLAVE, d.id); return d; });
    },

    usar(d) {
      if (this.dispositivo === d) return;
      this.dispositivo = d;
      this.caracteristica = null;
      d?.addEventListener('gattserverdisconnected', () => {
        this.caracteristica = null;
        if (!operacion) pintarActual();
      });
    },

    conectado() { return !!(this.caracteristica && this.dispositivo?.gatt?.connected); },

    async conectar() {
      if (this.conectado()) return;
      const d = this.dispositivo;
      // Al vencer, disconnect() aborta la conexión pendiente (si no, Chrome la deja
      // viva y bloquea los siguientes intentos).
      const servidor = await conTiempo(d.gatt.connect(), 12000, () => d.gatt.disconnect());
      if (this.cancelado) throw error('AbortError');
      const servicio = await conTiempo(servidor.getPrimaryService(this.SERVICIO), 8000, () => d.gatt.disconnect());
      this.caracteristica = await conTiempo(servicio.getCharacteristic(this.ESCRITURA), 8000, () => d.gatt.disconnect());
    },

    async enviar(texto, alAvanzar) {
      this.cancelado = false;
      try {
        await this.conectar();
      } catch (e) {
        if (e.name === 'AbortError' || this.cancelado) throw error('AbortError');
        // Un reintento limpio: a veces la primera conexión tras encender falla.
        this.desconectar();
        await this.conectar();
      }

      const prefijo = this.incompleto ? '~JA\n' : '';
      const bytes = new TextEncoder().encode(prefijo + texto);
      const escribir = this.caracteristica.writeValueWithResponse
        ? t => this.caracteristica.writeValueWithResponse(t)
        : t => this.caracteristica.writeValue(t);

      for (let i = 0; i < bytes.length; i += this.TROZO) {
        if (this.cancelado) {
          this.incompleto = i > 0;
          throw error('AbortError');
        }
        try {
          await conTiempo(escribir(bytes.slice(i, i + this.TROZO)), 8000);
        } catch (e) {
          this.incompleto = true;
          this.desconectar();
          throw e;
        }
        this.incompleto = true;
        alAvanzar?.(Math.min(1, (i + this.TROZO) / bytes.length));
      }
      this.incompleto = false;
    },

    cancelar() {
      this.cancelado = true;
      this.desconectar();
    },

    desconectar() {
      this.caracteristica = null;
      try { this.dispositivo?.gatt?.disconnect(); } catch (e) { /* ya desconectada */ }
    },

    async olvidar() {
      almacen.borrar(this.CLAVE);
      this.desconectar();
      try { await this.dispositivo?.forget?.(); } catch (e) { /* nada que olvidar */ }
      this.dispositivo = null;
    },
  };

  // ---------- Estado de la impresora en pantalla ----------
  function pintarActual() {
    const bp = BrowserPrint.activo;
    const destino = BrowserPrint.destino();
    const deEquipo = equipo.mac ? `${equipo.nombre} (${equipo.mac})` : null;

    if (bp === undefined) {
      actual.textContent = 'Buscando Zebra Browser Print…';
    } else if (destino?.exacto) {
      const d = destino.dispositivo;
      actual.textContent = `Usando Zebra Browser Print → ${deEquipo || d.name}${BrowserPrint.conexion(d) ? ` por ${BrowserPrint.conexion(d)}` : ''}. Lista.`;
    } else if (destino) {
      actual.textContent = `Browser Print está abierto pero no tiene la impresora ${deEquipo} en su lista. Se intentará enviar a esa MAC; si falla, descúbrela en la app Browser Print.`;
    } else if (Ble.dispositivo) {
      actual.textContent = `Usando Bluetooth directo: ${Ble.dispositivo.name || 'Zebra'}${Ble.conectado() ? ' (conectada)' : ' (se conecta al imprimir)'}.`;
    } else if (bp && !equipo.mac) {
      actual.textContent = 'Browser Print está abierto pero no tiene impresora predeterminada. Elige arriba la impresora de este equipo.';
    } else if (Ble.soportado()) {
      actual.textContent = `Browser Print no responde en este equipo. ${deEquipo ? `Abre la app Browser Print o toca «Conectar por Bluetooth directo» para ${deEquipo}.` : 'Elige arriba la impresora de este equipo.'}`;
    } else {
      actual.textContent = esIos() ? MSG.ios : 'Este navegador no tiene Bluetooth. Instala Zebra Browser Print o usa Chrome.';
    }
    btnOlvidar.hidden = !(Ble.dispositivo && !destino);
    btnElegir.hidden = !Ble.soportado();
  }

  function mensajeDeError(e, etapa) {
    const texto = String(e?.message || '');
    if (e?.name === 'AbortError') return ['aviso', MSG.cancelado];
    if (e?.name === 'NotFoundError' && /cancel/i.test(texto)) return ['aviso', MSG.sinSeleccion];
    if (e?.name === 'NotFoundError' && /adapter|bluetooth/i.test(texto)) return ['error', MSG.sinBluetooth];
    if (e?.name === 'SecurityError') return ['error', MSG.gesto];
    if (e?.name === 'NotSupportedError' || e?.name === 'TypeError') return ['error', MSG.noSoportado];
    if (e?.name === 'TimeoutError') return ['error', MSG.tiempo];
    if (e?.name === 'ServidorError') return ['error', MSG.servidor];
    if (e?.name === 'MacDesconocida') return ['error', MSG.macDesconocida(equipo.nombre, equipo.mac)];
    if (etapa === 'envio') return ['error', MSG.envio];
    return ['error', MSG.conexion];
  }

  // ---------- Operaciones ----------
  let ultimoNombre = 'la impresora';

  async function obtenerZpl(url) {
    const r = await conTiempo(fetch(url, { credentials: 'same-origin', cache: 'no-store' }), 15000);
    if (!r.ok) throw error('ServidorError', `HTTP ${r.status}`);
    return r.text();
  }

  /**
   * Envía el ZPL de `url` a la impresora disponible. El selector de Bluetooth,
   * si hace falta, se abre en la primera línea (dentro del toque).
   */
  async function enviarA(url, nombreOperacion) {
    if (operacion) return false;
    if (esIos() && !Ble.soportado()) {
      mostrar(MSG.ios, 'error');
      registrarFalla('ninguno', 'ios-sin-bluetooth', null);
      return false;
    }

    // Selector de Chrome solo si ya se sabe que Browser Print no sirve.
    const abrirSelector = BrowserPrint.activo !== undefined && !BrowserPrint.destino() && !Ble.dispositivo && Ble.soportado();
    const seleccion = abrirSelector ? Ble.elegir() : null; // ← sin ningún await antes
    const zpl = obtenerZpl(url);
    zpl.catch(() => {}); // se maneja abajo

    ocupar(nombreOperacion, { cancelable: true });
    panelVerificar.hidden = true;
    mostrar(abrirSelector ? 'Elige tu Zebra en la lista…' : MSG.buscando);

    let metodo = 'ninguno';
    let etapa = 'seleccion';
    let destino = null;
    try {
      if (seleccion) await seleccion;
      if (BrowserPrint.activo === undefined) await BrowserPrint.busqueda;
      destino = BrowserPrint.destino();

      if (destino) {
        metodo = 'browserprint';
      } else if (Ble.dispositivo) {
        metodo = 'bluetooth';
      } else if (Ble.soportado()) {
        // Browser Print terminó de buscar después del toque: el selector ya no
        // se puede abrir aquí. Se pide un toque más.
        liberar();
        mostrar('Browser Print no responde. Ábrelo en este equipo, o toca «Conectar por Bluetooth directo» y luego imprime.', 'aviso');
        pintarActual();
        return false;
      } else {
        throw error('NotSupportedError');
      }
      pintarActual();

      etapa = 'zpl';
      const texto = await zpl;

      etapa = 'envio';
      if (metodo === 'browserprint') {
        mostrar(MSG.imprimiendo);
        ultimoNombre = equipo.nombre || destino.dispositivo.name;
        try {
          await BrowserPrint.enviar(destino.dispositivo, texto);
        } catch (e) {
          if (destino.exacto) throw e;
          // Browser Print no tiene esa MAC en su lista y rechazó el envío directo.
          etapa = 'mac-desconocida';
          throw error('MacDesconocida', `${equipo.mac}: ${e.message}`);
        }
      } else {
        ultimoNombre = Ble.dispositivo.name || 'la impresora';
        mostrar(MSG.conectando(Ble.dispositivo.name || 'la impresora'));
        await Ble.enviar(texto, f => { avance(f); mostrar(`${MSG.imprimiendo} ${Math.round(f * 100)} %`); });
      }
      return metodo;
    } catch (e) {
      const [tipo, texto] = mensajeDeError(e, etapa);
      mostrar(texto, tipo);
      if (tipo === 'error') registrarFalla(metodo === 'ninguno' && Ble.soportado() ? 'bluetooth' : metodo, etapa, e);
      return false;
    } finally {
      if (operacion === nombreOperacion) liberar();
      pintarActual();
    }
  }

  async function imprimir() {
    const metodo = await enviarA(btn.dataset.zpl, 'imprimir');
    if (!metodo) return;
    mostrar(`Etiqueta enviada a ${ultimoNombre}. Revisa que se haya impreso completa.`, 'ok');
    panelVerificar.hidden = false;
    ($('btnConfirmar') || $('btnReintentar'))?.focus();
  }

  async function registrarImpresion() {
    const r = await fetch(btn.dataset.impresa, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const datos = await r.json().catch(() => null);
    if (!r.ok || !datos?.impresa) throw error('RegistroError', `HTTP ${r.status}`);
    // Cada pieza lleva su etiqueta: el embarque se puede guardar hasta que no falte ninguna.
    if (datos.pendientes) return datos;

    try { sessionStorage.setItem('tarima-impresa:' + uuid, '1'); } catch (e) { /* sin almacenamiento */ }
    if ('BroadcastChannel' in window) {
      const canal = new BroadcastChannel('movingupper-tarimas');
      canal.postMessage({ tipo: 'impresa', uuid });
      canal.close();
    }
    return datos;
  }

  // ---------- Botones ----------
  btn.addEventListener('click', imprimir);

  $('btnConfirmar')?.addEventListener('click', async () => {
    const boton = $('btnConfirmar');
    boton.disabled = true;
    try {
      const datos = await registrarImpresion();
      panelVerificar.hidden = true;
      mostrar(datos.pendientes ? MSG.faltan(datos.pendientes) : MSG.registrada, 'ok');
    } catch (e) {
      mostrar(MSG.noRegistrada, 'error');
      registrarFalla('ninguno', 'registro', e);
    } finally {
      boton.disabled = false;
    }
  });

  $('btnReintentar')?.addEventListener('click', () => {
    panelVerificar.hidden = true;
    imprimir();
  });

  btnCancelar.addEventListener('click', () => {
    if (!operacion) return; // ya terminó: no hay nada que cancelar
    btnCancelar.disabled = true;
    Ble.cancelar();
    mostrar('Cancelando…', 'aviso');
  });

  btnElegir.addEventListener('click', async () => {
    if (operacion) return;
    if (!Ble.soportado()) { mostrar(esIos() ? MSG.ios : MSG.noSoportado, 'error'); return; }

    let d;
    try {
      d = await Ble.elegir(); // primera línea del toque
    } catch (e) {
      const [tipo, texto] = mensajeDeError(e, 'seleccion');
      mostrar(texto, tipo);
      if (tipo === 'error') registrarFalla('bluetooth', 'seleccion', e);
      return;
    }

    // Prueba de conexión, con progreso y cancelable.
    ocupar('elegir', { cancelable: true });
    mostrar(MSG.conectando(d.name || 'la impresora'));
    Ble.cancelado = false;
    try {
      await Ble.conectar();
      mostrar(`Impresora lista: ${d.name || 'Zebra'} (Bluetooth). Ya puedes imprimir.`, 'ok');
    } catch (e) {
      const [tipo, texto] = mensajeDeError(e, 'conexion');
      mostrar(`${texto} La impresora quedó elegida; se volverá a intentar al imprimir.`, tipo);
      if (tipo === 'error') registrarFalla('bluetooth', 'conexion', e);
    } finally {
      liberar();
      pintarActual();
    }
  });

  btnBuscar.addEventListener('click', async () => {
    if (operacion) return;
    ocupar('buscar');
    mostrar(MSG.buscando);
    const activo = await BrowserPrint.buscar();
    liberar();
    pintarActual();

    // Inventario: qué impresoras ve Browser Print y con qué datos. Sirve para
    // registrar la MAC sin errores y para saber por qué no coincide.
    const detectadas = $('impresorasDetectadas');
    if (!activo) {
      detectadas.hidden = true;
      mostrar('Browser Print no responde en este equipo. Instálalo/ábrelo y acepta este sitio, o usa Bluetooth directo.', 'aviso');
      return;
    }
    const todas = [...BrowserPrint.lista];
    if (BrowserPrint.predeterminada && !todas.some(d => JSON.stringify(d) === JSON.stringify(BrowserPrint.predeterminada))) todas.push(BrowserPrint.predeterminada);
    const lineas = todas.map(d => {
      const mac = macDe(d);
      const marca = mac && mac === equipo.mac ? '  ← impresora de este equipo' : '';
      return `${d.name || '(sin nombre)'} · ${BrowserPrint.conexion(d) || '?'} · MAC ${mac || 'no indicada'}${marca}\n  uid: ${d.uid ?? '—'}`;
    });
    detectadas.hidden = false;
    detectadas.textContent = lineas.length ? lineas.join('\n') : '(Browser Print no tiene impresoras. En la app: Discover Printers.)';
    const coincide = equipo.mac && todas.some(d => macDe(d) === equipo.mac);
    mostrar(
      !todas.length ? 'Browser Print responde pero no tiene impresoras: descubre la ZQ620 en la app.'
        : !equipo.mac ? `Browser Print ve ${todas.length} impresora(s). Elige arriba la de este equipo.`
        : coincide ? `Browser Print ve la impresora ${equipo.nombre} (${equipo.mac}). Lista para imprimir.`
        : `Browser Print no ve la MAC ${equipo.mac}. Revisa que la MAC registrada sea la de la etiqueta de configuración.`,
      coincide ? 'ok' : 'aviso',
    );
    registrarFalla('browserprint', 'inventario', { name: 'Inventario', message: JSON.stringify({ equipo: equipo.mac, vistas: todas.map(d => ({ n: d.name, c: d.connection, u: d.uid })) }) });
  });

  selectorEquipo?.addEventListener('change', () => {
    almacen.guardar(CLAVE_EQUIPO, selectorEquipo.value);
    // La conexión Bluetooth directa era con otra impresora: se suelta.
    if (Ble.dispositivo && equipo.nombreBluetooth && Ble.dispositivo.name !== equipo.nombreBluetooth) {
      Ble.desconectar();
      Ble.dispositivo = null;
      almacen.borrar(Ble.CLAVE);
    }
    $('impresorasDetectadas').hidden = true;
    pintarActual();
    mostrar(equipo.mac ? `Este equipo imprimirá en ${equipo.nombre} (${equipo.mac}).` : 'Sin impresora fija: se usará la predeterminada de Browser Print.');
  });

  btnOlvidar.addEventListener('click', async () => {
    if (operacion) return;
    await Ble.olvidar();
    pintarActual();
    mostrar(MSG.olvidada);
  });

  btnCalibrar?.addEventListener('click', async () => {
    if (operacion) return;
    if (!window.confirm('Se guardará en la impresora el tamaño de etiqueta configurado y se calibrará el sensor de papel. La impresora avanzará una o dos etiquetas en blanco. ¿Continuar?')) return;
    // confirm() consume el toque: si hace falta elegir impresora, se pide antes.
    if (!BrowserPrint.destino() && !Ble.dispositivo) { mostrar(MSG.sinImpresora, 'aviso'); return; }
    if (await enviarA(btn.dataset.configuracion, 'calibrar')) mostrar(MSG.calibrada, 'ok');
  });

  // ---------- Imprimir con el navegador ----------
  // Solo para computadora con controlador Zebra instalado. Se imprime una copia
  // de la etiqueta colgada directo del <body>: así no arrastra márgenes ni el
  // espacio de la página (con visibility:hidden el resto seguía ocupando lugar
  // y la etiqueta salía corrida hacia abajo).
  let raiz = null;
  function prepararImpresion() {
    const zona = $('zonaImpresion');
    if (!zona || raiz) return;
    raiz = document.createElement('div');
    raiz.id = 'raizImpresion';
    raiz.append(...Array.from(zona.querySelectorAll('svg')).map(svg => {
      const hoja = document.createElement('div');
      hoja.className = 'hoja-impresion';
      hoja.appendChild(svg.cloneNode(true));
      return hoja;
    }));
    document.body.appendChild(raiz);
    document.documentElement.classList.add('imprimiendo');
  }
  function limpiarImpresion() {
    raiz?.remove();
    raiz = null;
    document.documentElement.classList.remove('imprimiendo');
  }
  window.addEventListener('beforeprint', prepararImpresion);
  window.addEventListener('afterprint', () => {
    limpiarImpresion();
    if (btn.dataset.impresa) {
      mostrar('Si imprimiste la etiqueta, confirma que salió bien.', 'info');
      panelVerificar.hidden = false;
    }
  });

  btnNavegador.addEventListener('click', () => {
    if (esAndroid && !window.confirm('En Android la impresión del navegador pasa por el servicio de impresión del sistema, que cambia el tamaño de la hoja: la etiqueta suele salir cortada o corrida. Usa Browser Print o Bluetooth directo. ¿Imprimir de todos modos?')) return;
    panelVerificar.hidden = true;
    prepararImpresion();
    window.print();
  });

  window.addEventListener('pagehide', () => Ble.desconectar());

  // ---------- Al cargar ----------
  // Browser Print se busca de inmediato (no necesita toque) para que el botón
  // Imprimir ya sepa si debe abrir el selector de Bluetooth.
  BrowserPrint.buscar().then(pintarActual);
  if (Ble.soportado()) Ble.recordado().then(pintarActual);
  pintarActual();
  if (esIos() && !Ble.soportado()) mostrar(MSG.ios, 'aviso');
})();
