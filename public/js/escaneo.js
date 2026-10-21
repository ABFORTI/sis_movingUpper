/*
 * Captura en handheld.
 *
 * 1. Teclado
 * 2. N/S sin recargar
 * 3. Aviso
 * 4. Foco
 * 5. Gatillo = Enter
 */
(function () {
  'use strict';

  const campos = Array.from(document.querySelectorAll('[data-escaneo]'));
  if (!campos.length) return;

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const tactil = window.matchMedia('(pointer: coarse)').matches;
  const almacen = {
    leer(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    guardar(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* sin almacenamiento */ } },
  };

  // ---------- 1. Teclado ----------
  const CLAVE_TECLADO = 'escaneo-teclado';
  let conTeclado = almacen.leer(CLAVE_TECLADO) === '1';

  function aplicarTeclado() {
    campos.forEach(c => c.setAttribute('inputmode', tactil && !conTeclado ? 'none' : 'text'));
    document.querySelectorAll('[data-teclado]').forEach(b => {
      b.setAttribute('aria-pressed', String(conTeclado));
      b.setAttribute('aria-label', conTeclado ? 'Ocultar teclado (usar escáner)' : 'Mostrar teclado para escribir a mano');
      b.classList.toggle('is-on', conTeclado);
    });
  }


  document.querySelectorAll('[data-captura-manual]').forEach(boton => {
    boton.addEventListener('click', () => {
      conTeclado = true;
      almacen.guardar(CLAVE_TECLADO, '1');
      aplicarTeclado();
      const campo = document.getElementById('numero_serie');
      if (campo && !campo.disabled) { campo.blur(); setTimeout(() => campo.focus(), 30); }
    });
  });

  document.querySelectorAll('[data-teclado]').forEach(boton => {
    boton.addEventListener('click', () => {
      conTeclado = !conTeclado;
      almacen.guardar(CLAVE_TECLADO, conTeclado ? '1' : '0');
      aplicarTeclado();

      const campo = boton.closest('.control')?.querySelector('[data-escaneo]');
      if (campo && !campo.disabled) { campo.blur(); setTimeout(() => campo.focus(), 30); }
    });
  });
  aplicarTeclado();

  // ---------- 3. Aviso ----------
  let audio = null;
  function tono(frecuencias, duracion) {
    try {
      audio ??= new (window.AudioContext || window.webkitAudioContext)();
      if (audio.state === 'suspended') audio.resume();
      let t = audio.currentTime;
      frecuencias.forEach(f => {
        const osc = audio.createOscillator();
        const vol = audio.createGain();
        osc.type = 'square';
        osc.frequency.value = f;
        vol.gain.setValueAtTime(0.08, t);
        vol.gain.exponentialRampToValueAtTime(0.001, t + duracion);
        osc.connect(vol).connect(audio.destination);
        osc.start(t);
        osc.stop(t + duracion);
        t += duracion + 0.04;
      });
    } catch (e) { /* sin audio: queda el aviso visual */ }
  }
  function vibrar(patron) { try { navigator.vibrate?.(patron); } catch (e) { /* sin vibración */ } }
  const aviso = {
    ok() { tono([1320], 0.07); vibrar(35); },

    repetido() { tono([880, 880], 0.06); vibrar([40, 60, 40]); },
    error() { tono([330, 220], 0.18); vibrar([180, 80, 180]); },
  };

  window.MovingUpperAviso = aviso;


  if (document.querySelector('.flash .alert-danger')) aviso.error();

  else if (document.querySelector('[data-escaneo][data-aviso-ok]') && document.querySelector('.flash .alert-success')) aviso.ok();


  document.querySelectorAll('[data-confirmar-eliminar]').forEach(boton => {
    boton.addEventListener('click', e => {
      const elegidas = Array.from(document.querySelectorAll(`input[name="ns[]"][form="${CSS.escape(boton.form.id)}"]:checked`))
        .map(c => c.closest('[data-ns-fila]')?.querySelector('[data-ns-valor]')?.textContent.trim()).filter(Boolean);
      if (!elegidas.length) {
        e.preventDefault();
        window.alert('Marca en la lista las N/S que quieres eliminar.');
        return;
      }
      const n = elegidas.length;
      if (!window.confirm(`¿Eliminar ${n === 1 ? 'esta N/S' : `estas ${n} N/S`} del embarque?\n\n${elegidas.join('\n')}\n\nPodrás volver a escanear${n === 1 ? 'la' : 'las'}.`)) e.preventDefault();
    });
  });

  // ---------- 4. Foco ----------
  function campoPrincipal() {
    return campos.find(c => !c.disabled && c.hasAttribute('autofocus'))
      || document.querySelector('[data-sku-input][autofocus]:not(:disabled)')
      || campos.find(c => !c.disabled);
  }
  document.addEventListener('click', e => {
    if (e.target.closest('a, button, input, select, textarea, label, summary, details, dialog, [contenteditable]')) return;
    if (window.getSelection()?.toString()) return;
    campoPrincipal()?.focus({ preventScroll: true });
  });

  if (tactil) campoPrincipal()?.scrollIntoView({ block: 'center' });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') campoPrincipal()?.focus({ preventScroll: true });
  });

  // ---------- 5. Gatillo = Enter ----------
  // Una lectura del gatillo se envía sola, aunque el escáner no mande Enter
  // (DataWedge sin "Send ENTER key", o con sufijo Tab). 
  const RAFAGA_MS = 40;  
  const FIN_MS = 120;    
  const MIN_CARACTERES = 4;

  const lectores = [...campos, ...document.querySelectorAll('[data-sku-input]')];
  lectores.forEach(campo => {
    const conTecladoEn = () => conTeclado || !campo.hasAttribute('data-escaneo');
    let ultimo = 0;
    let rafaga = 0;
    let reloj = null;
    let enviado = { valor: '', hasta: 0 };

    function enviar() {
      reloj = null;
      if (!campo.value.trim() || !campo.form || campo.disabled) return;
      enviado = { valor: campo.value.trim(), hasta: performance.now() + 400 };
      rafaga = 0;
      if (typeof campo.form.requestSubmit === 'function') campo.form.requestSubmit();
      else campo.form.dispatchEvent(new Event('submit', { cancelable: true })) && campo.form.submit();
    }

    campo.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        clearTimeout(reloj);
        reloj = null;
        rafaga = 0;
        const valor = campo.value.trim();
        if (performance.now() < enviado.hasta && (!valor || valor === enviado.valor)) e.preventDefault();
        return;
      }
      if (e.key === 'Tab' && rafaga >= MIN_CARACTERES && campo.value.trim()) {
        e.preventDefault();
        clearTimeout(reloj);
        enviar();
      }
    });

    campo.addEventListener('input', e => {
      const agregados = typeof e.data === 'string' ? e.data.length : 1;
      if (conTecladoEn() && (e.isComposing || agregados !== 1 || (e.inputType && e.inputType !== 'insertText'))) {
        clearTimeout(reloj);
        reloj = null;
        rafaga = 0;
        return;
      }
      const ahora = performance.now();
      rafaga = agregados > 1 || ahora - ultimo <= RAFAGA_MS || reloj !== null ? rafaga + agregados : agregados;
      ultimo = ahora;
      clearTimeout(reloj);
      reloj = rafaga >= MIN_CARACTERES ? setTimeout(enviar, FIN_MS) : null;
    });
  });

  const form = document.querySelector('form[data-escaneo-ns]');
  const campoNs = form?.querySelector('[data-escaneo]');
  if (!form || !campoNs || !window.fetch) return;

  const estado = document.createElement('p');
  estado.className = 'scan-estado';
  estado.setAttribute('role', 'status');
  estado.setAttribute('aria-live', 'polite');
  estado.hidden = true;
  form.querySelector('.field').appendChild(estado);
  const erroresViejos = form.querySelectorAll('[data-error-escaneo]');

  function mostrar(tipo, texto) {
    erroresViejos.forEach(e => { e.hidden = true; });
    estado.hidden = false;
    estado.dataset.tipo = tipo;
    estado.textContent = texto;
    campoNs.classList.toggle('is-invalid', tipo === 'error');
    campoNs.classList.remove('scan-flash-ok', 'scan-flash-error');
    void campoNs.offsetWidth; // reinicia la animación
    campoNs.classList.add(tipo === 'error' ? 'scan-flash-error' : 'scan-flash-ok');
  }

  function invalidarGuardado(uuid) {
    try { sessionStorage.removeItem('tarima-impresa:' + uuid); } catch (e) { /* sin almacenamiento */ }
    document.querySelectorAll(`[data-guardar][data-uuid="${CSS.escape(uuid)}"]`).forEach(b => {
      b.disabled = true;
      b.closest('[data-guardar-wrap]')?.setAttribute('title', 'Primero imprime las etiquetas de las piezas de este embarque');
    });
  }

  function reflejarEtiquetas(tarjeta, pendientes) {
    if (typeof pendientes !== 'number') return;
    const boton = tarjeta.querySelector('[data-etiquetas-boton]');
    boton?.classList.toggle('btn-accent', pendientes > 0);
    boton?.classList.toggle('btn-secondary', pendientes === 0);
    tarjeta.querySelectorAll('[data-etiquetas-pendientes]').forEach(n => {
      n.hidden = pendientes === 0;
      n.textContent = `${pendientes} sin imprimir`;
    });
  }

  function reflejar(datos) {
    document.querySelectorAll('[data-total-activo]').forEach(n => { n.textContent = datos.total_registro; });

    const tarjeta = document.querySelector(`.pallet[data-tarima="${CSS.escape(datos.tarima)}"]`);
    const linea = tarjeta?.querySelector(`[data-registro="${datos.registro}"]`);
    if (!tarjeta || !linea) { sinTarjeta = true; return; }

    tarjeta.querySelectorAll('[data-piezas]').forEach(n => { n.textContent = datos.piezas_tarima; });
    reflejarEtiquetas(tarjeta, datos.etiquetas_pendientes);
    linea.querySelectorAll('[data-total-registro]').forEach(n => { n.textContent = datos.total_registro; });
    linea.querySelector('[data-sin-ns]')?.setAttribute('hidden', '');
    const chips = linea.querySelector('[data-chips]');
    if (chips) {
      chips.hidden = false;
      const chip = document.createElement('span');
      chip.className = 'chip chip-nuevo';
      chip.dataset.ns = datos.numero_serie;
      if (datos.incidencia) {
        chip.className = 'chip chip-revision';
        chip.title = `Pendiente de revisión: no se encontró en el inventario (${datos.incidencia.folio})`;
        chip.textContent = '⚠ ' + datos.numero_serie;
        const etiqueta = document.createElement('span');
        etiqueta.className = 'chip-etiqueta';
        etiqueta.textContent = 'revisar';
        chip.appendChild(etiqueta);
      } else {
        chip.textContent = datos.numero_serie;
        if (datos.coincidencia) chip.title = 'Coincidió en: ' + datos.coincidencia;
      }
      chips.appendChild(chip);
      const max = Number(chips.dataset.chipsMax) || 0;
      while (max && chips.children.length > max) chips.firstElementChild.remove();
    }

    const lista = linea.querySelector('[data-anomalias-lista]');
    const plantilla = linea.querySelector('template[data-ns-plantilla]');
    if (lista && plantilla && datos.numero_serie_id) {
      const fila = plantilla.content.firstElementChild.cloneNode(true);
      const id = String(datos.numero_serie_id);
      fila.querySelectorAll('[id], [for], [action], [data-ns-id]').forEach(n => {
        ['id', 'for', 'action', 'value'].forEach(a => {
          if (n.hasAttribute(a)) n.setAttribute(a, n.getAttribute(a).replace('__ID__', id));
        });
      });
      fila.querySelector('[data-ns-valor]').textContent = datos.numero_serie;
      const entrada = fila.querySelector('[data-ns-entrada]');
      if (entrada) entrada.value = datos.numero_serie;
      fila.querySelector('summary')?.setAttribute('aria-label', `Editar la N/S ${datos.numero_serie}`);
      lista.appendChild(fila);
    } else if (!lista) {
      sinTarjeta = true; 
    }
    invalidarGuardado(datos.tarima);
  }


  const dialogo = document.getElementById('dlgSerie');
  const ui = dialogo && {
    icono: dialogo.querySelector('[data-serie-icono]'),
    titulo: dialogo.querySelector('[data-serie-titulo]'),
    ns: dialogo.querySelector('[data-serie-ns]'),
    texto: dialogo.querySelector('[data-serie-texto]'),
  };
  let cierreAutomatico = null;

  function cerrarDialogo() {
    clearTimeout(cierreAutomatico);
    if (dialogo?.open) dialogo.close();
    campoNs.focus({ preventScroll: true });
  }

  function avisarCoincidencia(datos) {
    if (!ui || typeof dialogo.show !== 'function') return;
    clearTimeout(cierreAutomatico);
    if (dialogo.open) dialogo.close();
    dialogo.dataset.tipo = 'ok';
    ui.icono.textContent = '✓';
    ui.titulo.textContent = 'Coincidencia encontrada en el inventario';
    ui.ns.textContent = datos.numero_serie;
    ui.texto.textContent = `Campo: ${datos.coincidencia}. Registrada.`;
    dialogo.show();
    campoNs.focus({ preventScroll: true });
    cierreAutomatico = setTimeout(cerrarDialogo, 1600);
  }


  let sinTarjeta = false;
  const cola = [];
  let enviando = false;

  let recargar = false;

  async function solicitar(valor) {
    const cuerpo = new FormData(form);
    cuerpo.set('numero_serie', valor);
    const respuesta = await fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
      body: cuerpo,
    });
    const datos = await respuesta.json().catch(() => null);
    return { respuesta, datos };
  }

  /** @returns {Promise<string|null>} null si se resolvió (guardada o repetida); el motivo si no. */
  async function enviar(valor) {
    let respuesta;
    let datos = null;
    try {
      ({ respuesta, datos } = await solicitar(valor));
    } catch (e) {
      return `Sin conexión: la N/S ${valor} NO se guardó. Vuelve a escanearla.`;
    }

    if (respuesta.ok && datos?.estado === 'repetida') {
      mostrar('aviso', datos.mensaje);
      aviso.repetido();
      return null;
    }

    if (respuesta.ok && datos?.estado === 'registrada') {
      reflejar(datos);
      mostrar(datos.incidencia ? 'aviso' : 'ok', datos.mensaje);
      if (datos.incidencia) aviso.repetido();
      else { aviso.ok(); avisarCoincidencia(datos); }
      return null;
    }

    if (respuesta.status === 419) return 'Tu sesión expiró. Recarga la página e inicia sesión de nuevo.';
    if (respuesta.status === 403) {
      recargar = true;
      return 'Este embarque ya no es tuyo o cambió.';
    }
    const texto = datos?.errors?.numero_serie?.[0] || datos?.message || `No se pudo guardar la N/S ${valor}.`;
    if (/SKU activo|guardad[oa]|anulad[oa]|mudanza/i.test(texto)) recargar = true;
    return texto;
  }

  async function procesar() {
    if (enviando) return;
    enviando = true;
    const rechazos = [];
    while (cola.length) {
      const motivo = await enviar(cola.shift());
      if (motivo) {
        rechazos.push(motivo);
        mostrar('error', rechazos.join(' · '));
        aviso.error();
      } else if (rechazos.length) {
        mostrar('error', rechazos.join(' · '));
      }
    }
    enviando = false;
    if (sinTarjeta || recargar) setTimeout(() => location.reload(), rechazos.length ? 2500 : 1200);
  }

  form.addEventListener('submit', e => {
    e.preventDefault();
    const valor = campoNs.value.trim();
    if (!valor) return;
    campoNs.value = '';
    cola.push(valor);
    procesar();
    campoNs.focus();
  });
})();
