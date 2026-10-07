(function () {
  const CANAL = 'movingupper-tarimas';
  const clave = uuid => 'tarima-impresa:' + uuid;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const MSG_ERROR = 'No fue posible guardar la información. Inténtalo nuevamente.';

  const almacen = {
    tiene(uuid) { try { return sessionStorage.getItem(clave(uuid)) === '1'; } catch (e) { return false; } },
    quitar(uuid) { try { sessionStorage.removeItem(clave(uuid)); } catch (e) { /* sin almacenamiento */ } },
  };

  function botonesDe(uuid) {
    return document.querySelectorAll(`[data-guardar][data-uuid="${CSS.escape(uuid)}"]`);
  }

  function habilitar(uuid) {
    botonesDe(uuid).forEach(btn => {
      btn.disabled = false;
      btn.closest('[data-guardar-wrap]')?.removeAttribute('title');
    });
  }

  function deshabilitar(btn) {
    btn.disabled = true;
    btn.closest('[data-guardar-wrap]')?.setAttribute('title', 'Primero imprime la etiqueta de esta tarima');
  }

  function alerta(tipo, texto) {
    const plantilla = document.getElementById('tpl-alerta-' + tipo);
    const contenedor = document.querySelector('.flash');
    if (!plantilla || !contenedor) { window.alert(texto); return; }

    const nodo = plantilla.content.firstElementChild.cloneNode(true);
    nodo.querySelector('.alert-title').textContent = texto;
    const cerrar = () => { nodo.classList.add('is-leaving'); setTimeout(() => nodo.remove(), 300); };
    nodo.querySelector('.alert-close').addEventListener('click', cerrar);
    if (tipo === 'success') setTimeout(cerrar, 6000);
    contenedor.appendChild(nodo);
    nodo.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function quitarTarimaDeLaVista(uuid) {
    const tarjeta = document.querySelector(`.pallet[data-tarima="${CSS.escape(uuid)}"]`);
    if (tarjeta) {
      tarjeta.classList.add('is-leaving');
      setTimeout(() => tarjeta.remove(), 300);
    }
    // Ya no se puede capturar en ella.
    document.querySelectorAll(`select[name="tarima"] option[value="${CSS.escape(uuid)}"]`).forEach(o => o.remove());
  }

  function mensajeDeError(respuesta, datos) {
    if (respuesta.status === 419) return 'Tu sesión expiró. Recarga la página e inténtalo nuevamente.';
    if (respuesta.status === 403) return 'No tienes permiso para guardar esta tarima.';
    const detalle = datos?.errors?.tarima?.[0];
    return detalle ? `${MSG_ERROR} ${detalle}` : MSG_ERROR;
  }

  async function guardar(btn) {
    if (btn.disabled || btn.classList.contains('is-loading')) return;

    const uuid = btn.dataset.uuid;
    const texto = btn.querySelector('[data-guardar-texto]');
    btn.disabled = true;
    btn.classList.add('is-loading');
    btn.setAttribute('aria-busy', 'true');
    texto.textContent = 'Guardando...';

    let respuesta = null;
    let datos = null;
    try {
      respuesta = await fetch(btn.dataset.guardar, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      datos = await respuesta.json().catch(() => null);
    } catch (e) {
      respuesta = null; // Error de red.
    }

    btn.classList.remove('is-loading');
    btn.removeAttribute('aria-busy');
    texto.textContent = 'Guardar';

    if (respuesta?.ok && datos?.guardada) {
      almacen.quitar(uuid);
      if (datos.recargar) { window.location.reload(); return; }
      quitarTarimaDeLaVista(uuid);
      alerta('success', datos.mensaje);
      return;
    }

    alerta('danger', respuesta ? mensajeDeError(respuesta, datos) : MSG_ERROR);

    if (respuesta?.status === 422) {
      // El servidor dice que falta imprimir (o el contenido cambió): vuelve a bloquearlo.
      almacen.quitar(uuid);
      deshabilitar(btn);
    } else {
      btn.disabled = false; // Permite reintentar.
    }
  }

  document.querySelectorAll('[data-guardar]').forEach(btn => {
    btn.addEventListener('click', () => guardar(btn));
    // En una carga normal el servidor es la fuente de verdad: si dice "no impresa",
    // se descarta cualquier aviso viejo del navegador.
    if (btn.disabled) almacen.quitar(btn.dataset.uuid);
  });

  // Regreso con "Atrás": la página viene de la caché del navegador sin pedirla al servidor.
  window.addEventListener('pageshow', e => {
    if (!e.persisted) return;
    document.querySelectorAll('[data-guardar]:disabled').forEach(btn => {
      if (almacen.tiene(btn.dataset.uuid)) habilitar(btn.dataset.uuid);
    });
  });

  // Impresión hecha en otra pestaña.
  if ('BroadcastChannel' in window) {
    new BroadcastChannel(CANAL).addEventListener('message', e => {
      if (e.data?.tipo === 'impresa' && e.data.uuid) habilitar(e.data.uuid);
    });
  }
})();
