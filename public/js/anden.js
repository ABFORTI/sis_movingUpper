/*
 * Andén y recepción en handheld: escaneo continuo sin recargar.
 *
 * 1. Cada lectura entra a una cola guardada en el equipo (localStorage) y se
 *    envía en orden. Si no hay red, el servidor tarda o la sesión expiró, la
 *    lectura queda "Pendiente de envío" y se reintenta sola (al volver la red,
 *    al regresar a la app o con el botón). Nunca se muestra "Guardado" sin la
 *    respuesta del servidor.
 * 2. Reintentar no duplica: cargar o recibir lo que ya está en el tráiler
 *    responde "repetido" sin cambiar nada (lo garantiza el servidor). Si un
 *    reintento responde "repetido", el primer envío sí llegó: se muestra como
 *    guardado.
 * 3. Una doble lectura del gatillo (mismo código en menos de 1.5 s) se ignora.
 * 4. Lo que el servidor rechaza no bloquea las lecturas siguientes; su aviso
 *    queda en la lista.
 * Sin JavaScript, el formulario funciona como siempre (una recarga por lectura).
 */
(function () {
  'use strict';

  const raiz = document.querySelector('[data-anden]');
  if (!raiz || !window.fetch) return;

  const form = raiz.querySelector('[data-anden-form]');
  const campo = form.querySelector('[data-escaneo]');
  const caja = raiz.querySelector('[data-anden-estado]');
  const acciones = raiz.querySelector('[data-anden-acciones]');
  const barraPendientes = raiz.querySelector('[data-anden-pendientes]');
  const log = raiz.querySelector('[data-anden-log]');
  const lista = document.querySelector('[data-anden-lista]');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const modo = raiz.dataset.modo; // cargar | recibir
  const aviso = window.MovingUpperAviso || { ok() {}, repetido() {}, error() {} };

  const PREFIJO = 'anden-cola:';
  const CLAVE = PREFIJO + raiz.dataset.clave;
  const VENTANA_DOBLE = 1500;
  const TIEMPO_LIMITE = 15000;
  const MAX_LOG = 20;

  const ESTADOS = {
    pendiente: { icono: '⏳', titulo: 'Pendiente de envío' },
    ok: { icono: '✓', titulo: modo === 'cargar' ? 'Cargada' : 'Recibida' },
    repetido: { icono: '↺', titulo: 'Ya estaba registrada' },
    no_esperada: { icono: '⚠', titulo: 'No viene en este tráiler' },
    error: { icono: '✕', titulo: 'Rechazada' },
  };

  const almacen = {
    leer(k) { try { return JSON.parse(localStorage.getItem(k) || '[]'); } catch (e) { return []; } },
    guardar(k, v) { try { v.length ? localStorage.setItem(k, JSON.stringify(v)) : localStorage.removeItem(k); } catch (e) { /* sin almacenamiento: la cola vive solo en memoria */ } },
    claves() { try { return Object.keys(localStorage); } catch (e) { return []; } },
  };

  let cola = almacen.leer(CLAVE);
  let enviando = false;
  let reloj = null;
  let espera = 2000;
  const ultimas = new Map();

  function nuevoId() {
    return window.crypto?.randomUUID?.() || Date.now().toString(36) + Math.random().toString(36).slice(2);
  }

  // ---------- Pantalla ----------
  function mostrar(estado, texto, codigo) {
    const e = ESTADOS[estado];
    caja.hidden = false;
    caja.dataset.tipo = estado;
    caja.querySelector('[data-icono]').textContent = e.icono;
    caja.querySelector('[data-titulo]').textContent = codigo ? `${e.titulo} · ${codigo}` : e.titulo;
    caja.querySelector('[data-texto]').textContent = texto;
    raiz.querySelectorAll('[data-error-escaneo]').forEach(n => { n.hidden = true; });
    campo.classList.toggle('is-invalid', estado === 'error' || estado === 'no_esperada');
  }

  function anotar(item, estado, texto) {
    let li = log.querySelector(`[data-id="${CSS.escape(item.id)}"]`);
    if (!li) {
      li = document.createElement('li');
      li.dataset.id = item.id;
      li.innerHTML = '<span class="anden-log-icono" aria-hidden="true"></span><span class="anden-log-codigo mono"></span><span class="anden-log-estado"></span><span class="anden-log-texto"></span>';
      li.querySelector('.anden-log-codigo').textContent = item.codigo;
      log.prepend(li);
      while (log.children.length > MAX_LOG) log.lastElementChild.remove();
    }
    li.dataset.tipo = estado;
    li.querySelector('.anden-log-icono').textContent = ESTADOS[estado].icono;
    li.querySelector('.anden-log-estado').textContent = ESTADOS[estado].titulo;
    li.querySelector('.anden-log-texto').textContent = texto || '';
  }

  function pintarPendientes(motivo) {
    const n = cola.length;
    barraPendientes.hidden = n === 0;
    if (n) {
      barraPendientes.querySelector('[data-texto]').textContent =
        `${n} ${n === 1 ? 'lectura pendiente' : 'lecturas pendientes'} de enviar. ${motivo || 'Se reintentará sola.'} No cierres la sesión.`;
    }
  }

  function actualizarLista(d) {
    document.querySelectorAll('[data-anden-total]').forEach(n => { n.textContent = d.total; });
    document.querySelectorAll('[data-anden-recibidas]').forEach(n => { n.textContent = d.recibidas; });
    if (!lista) return;

    let fila = lista.querySelector(`[data-tarima-id="${d.tarima_id}"]`);
    if (!fila && modo === 'cargar' && d.tarima_cargadas > 0) {
      const tpl = document.getElementById('tpl-fila-tarima');
      fila = tpl.content.firstElementChild.cloneNode(true);
      fila.dataset.tarimaId = d.tarima_id;
      fila.querySelector('[data-codigo]').textContent = d.tarima;
      fila.querySelector('[data-hora]').textContent = new Date().toTimeString().slice(0, 5);
      const bajar = fila.querySelector('form');
      if (d.bajar_url) {
        bajar.action = d.bajar_url;
        bajar.dataset.motivo = `¿Bajar el embarque ${d.tarima} del tráiler?`;
      } else {
        bajar.remove();
      }
      lista.prepend(fila);
      document.querySelector('[data-anden-vacio]')?.setAttribute('hidden', '');
      document.querySelectorAll('[data-anden-total-tarimas]').forEach(n => { n.textContent = lista.children.length; });
    }
    if (!fila) return;

    fila.querySelector('[data-piezas]').textContent = d.tarima_cargadas;
    const progreso = fila.querySelector('[data-progreso]');
    if (modo === 'recibir' && progreso) {
      const completa = d.tarima_recibidas === d.tarima_cargadas;
      progreso.className = 'badge ' + (completa ? 'badge-success' : 'badge-warn');
      progreso.textContent = completa ? '✓ Recibida' : `${d.tarima_recibidas} de ${d.tarima_cargadas}`;
      fila.classList.toggle('is-recibida', completa);
    }
    fila.classList.remove('anden-resaltar');
    void fila.offsetWidth;
    fila.classList.add('anden-resaltar');
  }

  // ---------- Envío ----------
  function enviarA(url, codigo) {
    const cuerpo = new FormData();
    cuerpo.set('codigo', codigo);
    const control = new AbortController();
    const limite = setTimeout(() => control.abort(), TIEMPO_LIMITE);

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      signal: control.signal,
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
      body: cuerpo,
    }).finally(() => clearTimeout(limite));
  }

  /** @returns {Promise<{estado: string, texto: string, datos?: object}|{red: string}>} */
  async function enviar(item) {
    let r;
    try {
      r = await enviarA(form.action, item.codigo);
    } catch (e) {
      return { red: e.name === 'AbortError' ? 'El servidor no respondió a tiempo.' : 'Sin conexión.' };
    }
    const d = await r.json().catch(() => null);

    if (r.ok && d?.estado) return { estado: d.estado, texto: d.mensaje, datos: d };
    if (r.status === 419 || r.status === 401) return { red: 'Tu sesión expiró: recarga la página e inicia sesión; lo pendiente se enviará después.', sesion: true };
    if (r.status === 422) return { estado: 'error', texto: d?.errors?.codigo?.[0] || d?.message || 'Código rechazado.' };
    if (r.status === 403) return { estado: 'error', texto: 'No tienes permiso para esta operación.' };
    return { red: `El servidor respondió con error (${r.status}).` };
  }

  async function procesar() {
    if (enviando) return;
    enviando = true;
    clearTimeout(reloj);

    while (cola.length) {
      const item = cola[0];
      const res = await enviar(item);

      if (res.red) {
        item.intentos = (item.intentos || 0) + 1;
        almacen.guardar(CLAVE, cola);
        anotar(item, 'pendiente', res.red);
        mostrar('pendiente', `${res.red} La lectura NO se ha guardado todavía.`, item.codigo);
        if (item.intentos === 1) aviso.error(); // una vez, no en cada reintento
        pintarPendientes(res.red);
        if (!res.sesion) {
          reloj = setTimeout(procesar, espera);
          espera = Math.min(espera * 2, 30000);
        }
        enviando = false;
        return;
      }

      cola.shift();
      almacen.guardar(CLAVE, cola);
      espera = 2000;

      // "Repetido" en un reintento = el primer envío sí se guardó.
      const estado = res.estado === 'repetido' && item.intentos ? 'ok' : res.estado;
      const texto = estado === 'ok' && res.estado === 'repetido' ? `${res.texto} (confirmado al reconectar)` : res.texto;
      anotar(item, estado, texto);
      mostrar(estado, texto, item.codigo);
      if (res.datos) actualizarLista(res.datos);

      acciones.hidden = true;
      if (estado === 'ok') aviso.ok();
      else if (estado === 'repetido') aviso.repetido();
      else {
        aviso.error();
        // El tráiler cambió de estado (salió, se cerró): la pantalla está desfasada.
        if (/ya salió|todavía no sale|ya se cerró|no está en tránsito/i.test(texto)) setTimeout(() => location.reload(), 2500);
      }
    }

    pintarPendientes();
    enviando = false;
  }

  form.addEventListener('submit', e => {
    // El botón de incidencia del flujo sin JavaScript se envía normal.
    if (e.submitter?.hasAttribute('formaction')) return;
    e.preventDefault();

    const codigo = campo.value.trim();
    campo.value = '';
    campo.focus({ preventScroll: true });
    if (!codigo) return;

    const ahora = Date.now();
    if (ahora - (ultimas.get(codigo) || 0) < VENTANA_DOBLE) return; // doble lectura del gatillo
    ultimas.set(codigo, ahora);

    const item = { id: nuevoId(), codigo, t: ahora };
    cola.push(item);
    almacen.guardar(CLAVE, cola);
    anotar(item, 'pendiente', 'Enviando…');
    pintarPendientes();
    procesar();
  });

  barraPendientes.querySelector('[data-reintentar]').addEventListener('click', () => {
    espera = 2000;
    procesar();
    campo.focus({ preventScroll: true });
  });
  window.addEventListener('online', () => { espera = 2000; procesar(); });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && cola.length) procesar();
  });

  // ---------- Recuperación ----------
  // Lecturas que quedaron pendientes al cerrar o recargar: se muestran y se envían.
  cola.forEach(item => anotar(item, 'pendiente', 'Quedó pendiente; reenviando…'));
  if (cola.length) procesar();

  // Lecturas pendientes de otra etapa de este tráiler (p. ej. carga sin enviar
  // y el tráiler ya salió): no se envían a otra operación ni se pierden en silencio.
  const base = CLAVE.slice(0, CLAVE.lastIndexOf('-') + 1);
  almacen.claves().filter(k => k.startsWith(base) && k !== CLAVE).forEach(k => {
    const viejas = almacen.leer(k);
    if (!viejas.length) return;
    const div = document.createElement('div');
    div.className = 'alert alert-danger';
    div.setAttribute('role', 'alert');
    const p = document.createElement('p');
    p.textContent = `${viejas.length} lecturas de la etapa anterior nunca llegaron al servidor y NO quedaron registradas: ${viejas.map(v => v.codigo).join(', ')}. Avisa a tu supervisor.`;
    const quitar = document.createElement('button');
    quitar.type = 'button';
    quitar.className = 'btn btn-secondary btn-sm';
    quitar.textContent = 'Entendido, quitar aviso';
    quitar.addEventListener('click', () => { almacen.guardar(k, []); div.remove(); });
    div.append(p, quitar);
    raiz.before(div);
  });
})();
