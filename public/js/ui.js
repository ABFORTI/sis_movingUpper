(function () {
  function cerrar(alerta) {
    alerta.classList.add('is-leaving');
    setTimeout(() => alerta.remove(), 300);
  }
  document.querySelectorAll('[data-alert]').forEach(alerta => {
    alerta.querySelector('.alert-close')?.addEventListener('click', () => cerrar(alerta));
    if (alerta.dataset.autohide) setTimeout(() => cerrar(alerta), 5000);
  });


  document.querySelectorAll('[data-nav-mas]').forEach(menu => {
    document.addEventListener('click', e => {
      if (menu.open && !menu.querySelector('.nav-mas-panel').contains(e.target) && !menu.querySelector('summary').contains(e.target)) menu.open = false;
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
    });
  });

  const sw = document.body.dataset.sw;
  if (sw && 'serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => navigator.serviceWorker.register(sw, { scope: '/' }).catch(() => {}));
  }


  const dialogo = document.getElementById('dlgMotivo');
  document.addEventListener('submit', e => {
    const form = e.target.closest('form[data-motivo]');
    if (!form) return;
    if (form.motivo.value.trim()) return; // ya trae motivo: se envía
    e.preventDefault();

    if (!dialogo || typeof dialogo.showModal !== 'function') {
      const m = window.prompt(form.dataset.motivo + '\n\nEscribe el motivo:');
      if (m && m.trim()) { form.motivo.value = m.trim(); form.submit(); }
      return;
    }

    const campo = dialogo.querySelector('textarea');
    dialogo.querySelector('[data-titulo]').textContent = form.dataset.motivo;
    campo.value = '';
    dialogo.returnValue = '';
    dialogo.showModal();
    campo.focus();
    dialogo.addEventListener('close', () => {
      if (dialogo.returnValue === 'ok' && campo.value.trim()) {
        form.motivo.value = campo.value.trim();
        form.submit();
      }
    }, { once: true });
  });

  document.querySelectorAll('form[data-loading]').forEach(form => {
    form.addEventListener('submit', e => {
      if (e.defaultPrevented) return;
      const btn = form.querySelector('button[type="submit"]');
      if (!btn) return;
      btn.classList.add('is-loading');
      setTimeout(() => { btn.disabled = true; }, 0);
    });
  });

  document.querySelectorAll('[data-toggle-password]').forEach(btn => {
    const input = document.getElementById(btn.dataset.togglePassword);
    btn.addEventListener('click', () => {
      const mostrar = input.type === 'password';
      input.type = mostrar ? 'text' : 'password';
      btn.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
      btn.setAttribute('aria-pressed', String(mostrar));
      btn.querySelector('[data-eye]').toggleAttribute('hidden', mostrar);
      btn.querySelector('[data-eye-off]').toggleAttribute('hidden', !mostrar);
      input.focus();
    });
  });

  document.querySelectorAll('[data-caps]').forEach(aviso => {
    const input = document.getElementById(aviso.dataset.caps);
    const revisar = e => {
      if (typeof e.getModifierState === 'function') aviso.classList.toggle('is-on', e.getModifierState('CapsLock'));
    };
    input.addEventListener('keyup', revisar);
    input.addEventListener('keydown', revisar);
    input.addEventListener('blur', () => aviso.classList.remove('is-on'));
  });

  document.querySelectorAll('[data-filter]').forEach(input => {
    const filas = document.querySelectorAll(input.dataset.filter + ' tbody tr');
    const contador = document.querySelector(input.dataset.filterCount || '');
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      let visibles = 0;
      filas.forEach(fila => {
        const ok = fila.textContent.toLowerCase().includes(q);
        fila.hidden = !ok;
        if (ok) visibles++;
      });
      if (contador) contador.textContent = visibles;
    });
  });

  // Excel de trabajo: el selector de embarque solo aplica (y es obligatorio) con alcance "Por embarque".
  document.querySelectorAll('[data-excel-alcance]').forEach(form => {
    const alcance = form.querySelector('[data-alcance]');
    const embarque = form.querySelector('[data-alcance-embarque]');
    const sincronizar = () => {
      const activo = alcance.value === 'embarque';
      embarque.hidden = embarque.disabled = !activo;
      embarque.required = activo;
    };
    alcance.addEventListener('change', () => { sincronizar(); if (!embarque.disabled) embarque.focus(); });
    sincronizar();
  });

  // Selector de PDF: muestra el archivo elegido y avisa antes de enviar si no es PDF o pesa demasiado.
  // Es solo ayuda visual: el servidor vuelve a validar todo.
  document.querySelectorAll('[data-file-drop]').forEach(zona => {
    const input = zona.querySelector('input[type="file"]');
    const nombre = zona.querySelector('[data-file-name]');
    const pista = zona.querySelector('[data-file-hint]');
    const original = { nombre: nombre.textContent, pista: pista.textContent };
    const maxBytes = Number(zona.dataset.maxKb) * 1024;
    const legible = b => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';

    input.addEventListener('change', () => {
      const archivo = input.files[0];
      zona.classList.remove('has-file', 'is-invalid');
      input.setCustomValidity('');
      if (!archivo) { nombre.textContent = original.nombre; pista.textContent = original.pista; return; }

      nombre.textContent = archivo.name;
      let error = '';
      if (!/\.pdf$/i.test(archivo.name) || (archivo.type && archivo.type !== 'application/pdf')) error = 'El archivo debe ser un PDF.';
      else if (archivo.size > maxBytes) error = 'Pesa ' + legible(archivo.size) + '; el máximo es ' + legible(maxBytes) + '.';

      input.setCustomValidity(error);
      zona.classList.add(error ? 'is-invalid' : 'has-file');
      pista.textContent = error || legible(archivo.size) + ' · listo para subir';
    });
    ['dragenter', 'dragover'].forEach(ev => input.addEventListener(ev, () => zona.classList.add('is-dragover')));
    ['dragleave', 'drop'].forEach(ev => input.addEventListener(ev, () => zona.classList.remove('is-dragover')));
  });
})();
