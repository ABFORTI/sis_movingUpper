@extends('layouts.app')

@php use App\Models\MovimientoPartida; @endphp

@section('title', "Corregir {$tarima->nombre}")

@section('content')
<main class="page page-narrow">

  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route('historial') }}">Historial</a>
    <x-icon name="chevron-right" />
    <span>{{ $tarima->identificador }}</span>
  </nav>

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="pencil" class="icon-sm" /> Corrección</p>
      <h1 class="page-title">Corregir {{ $tarima->nombre }}</h1>
      <p class="page-sub">Mueve, retira, elimina, agrega o edita N/S. Cada pieza conserva su consecutivo, el mismo que ya recibió el cliente en el Excel.</p>
    </div>
  </div>

  @if (session('reimprimir'))
    <div class="alert alert-success" style="margin-bottom:16px;" role="status">
      <x-icon name="printer" />
      <div class="alert-body">
        <p class="alert-title">Reimprime la etiqueta de la N/S <span class="mono">{{ session('reimprimir.numero_serie') }}</span></p>
        <p class="alert-text">Solo sale esa etiqueta; las de las demás piezas no cambian.</p>
      </div>
      <a class="btn btn-primary btn-sm" href="{{ session('reimprimir.url') }}" data-reimprimir><x-icon name="printer" /> Reimprimir</a>
    </div>
  @endif

  @if ($sinPartida > 0)
    {{-- Guardado por fuera del botón Guardar: sus N/S existen (Ver piezas) pero sin consecutivo (Corregir, Excel). --}}
    <form method="POST" action="{{ route('tarimas.partidas.completar', $tarima) }}" class="alert alert-danger" style="margin-bottom:16px;" role="alert" data-loading>
      @csrf
      <x-icon name="alert" />
      <div class="alert-body">
        <p class="alert-title">{{ $sinPartida }} {{ $sinPartida === 1 ? 'pieza registrada no tiene' : 'piezas registradas no tienen' }} consecutivo</p>
        <p class="alert-text">El embarque figura como guardado, pero sus piezas no se numeraron: por eso no aparecen aquí ni en el Excel de trabajo. Complétalas para numerarlas con los siguientes consecutivos de la mudanza; sus N/S y etiquetas no cambian.</p>
      </div>
      <button type="submit" class="btn btn-primary btn-sm"><span class="spinner" aria-hidden="true"></span><x-icon name="check" class="btn-icon" /> Completar piezas</button>
    </form>
  @endif

  @if ($tarima->requiereReimpresion())
    <div class="alert alert-warn" style="margin-bottom:16px;">
      <x-icon name="printer" />
      <div class="alert-body">
        <p class="alert-title">Hay piezas movidas a este embarque con la etiqueta anterior</p>
        <p class="alert-text">Imprime su etiqueta nueva y pégala sobre la anterior. Hasta entonces este embarque no se carga al tráiler.</p>
      </div>
      <a class="btn btn-secondary btn-sm" href="{{ route('etiquetas.show', $tarima) }}"><x-icon name="printer" /> Reimprimir</a>
    </div>
  @endif

  @php $formulario = old('_form'); @endphp
  <form method="POST" action="{{ route('tarimas.partidas.mover', $tarima) }}" class="card" data-loading>
    @csrf
    <input type="hidden" name="_form" value="piezas">
    <div class="card-head">
      <div>
        <h2 class="card-title"><x-icon name="package" /> Piezas en el embarque</h2>
        <p class="card-desc">{{ $tarima->partidas->count() }} {{ Str::plural('pieza', $tarima->partidas->count()) }} · selecciona las que quieres corregir.</p>
      </div>
    </div>

    @if ($tarima->partidas->isEmpty())
      @if ($sinPartida > 0)
        <div class="empty">
          <div class="empty-icon"><x-icon name="alert" class="icon-xl" /></div>
          <p class="empty-title">Tiene {{ $sinPartida }} {{ Str::plural('pieza', $sinPartida) }} sin consecutivo</p>
          <p class="empty-text">Usa «Completar piezas» (arriba) para que aparezcan aquí y se puedan corregir.</p>
        </div>
      @elseif ($trasladadas->isNotEmpty())
        {{-- No está vacío: sus piezas siguen en la mudanza, en otro embarque. --}}
        <div class="empty">
          <div class="empty-icon"><x-icon name="arrow-right" class="icon-xl" /></div>
          <p class="empty-title">Las piezas se movieron al {{ $trasladadas->count() === 1 ? 'embarque' : 'los embarques' }} {{ $trasladadas->join(', ', ' y ') }}</p>
          <p class="empty-text">Consulta y corrige las piezas desde {{ $trasladadas->count() === 1 ? 'ese embarque' : 'esos embarques' }}. Los movimientos quedan abajo.</p>
        </div>
      @else
        <div class="empty">
          <div class="empty-icon"><x-icon name="inbox" class="icon-xl" /></div>
          <p class="empty-title">El embarque no tiene piezas</p>
          <p class="empty-text">Se retiraron o eliminaron todas. Si ya no se usará, el administrador puede anularlo.</p>
        </div>
      @endif
    @else
      <div class="toolbar">
        <div class="control">
          <x-icon name="search" class="icon-sm" />
          <input type="search" class="input" placeholder="Escanea o busca N/S" data-marcar-escaneo
                 autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="done"
                 data-filter="#tablaPartidas" data-filter-count="#conteoPartidas" aria-label="Buscar piezas">
        </div>
        <span class="toolbar-count"><b id="conteoPartidas">{{ $tarima->partidas->count() }}</b> de {{ $tarima->partidas->count() }}</span>
      </div>

      <div class="table-wrap">
        <table class="table table-stack table-partidas" id="tablaPartidas">
          <thead><tr><th class="num"><span class="sr-only">Elegir</span></th><th>#</th><th>SKU</th><th>N/S</th><th>Descripción</th></tr></thead>
          <tbody>
            @foreach ($tarima->partidas as $partida)
              <tr @class(['is-revision' => $partida->pendienteRevision()])>
                <td class="num c-check" data-label="">
                  <input type="checkbox" class="checkbox" name="partidas[]" value="{{ $partida->id }}" id="p{{ $partida->id }}"
                         @checked(in_array($partida->id, old('partidas', [])))>
                </td>
                <td class="mono" data-label="Consecutivo"><label for="p{{ $partida->id }}">{{ $partida->consecutivo }}</label></td>
                <td data-label="SKU"><span class="code">{{ $partida->sku }}</span></td>
                <td data-label="N/S">
                  <span class="mono">{{ $partida->ns_mostrada }}</span>
                  @if ($partida->pendienteRevision())<x-pendiente-revision :compacto="true" />@endif
                  @foreach ($partida->incidencias as $inc)
                    <a href="{{ route('incidencias.show', $inc) }}" class="badge badge-warn" title="{{ $inc->titulo }}">{{ $inc->folio }}</a>
                  @endforeach
                </td>
                <td data-label="Descripción">{{ $partida->descripcion_mostrada }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <div class="card-body" style="border-top:1px solid var(--border);">
        <div class="field">
          <label for="destino" class="label">Mover a <span class="optional">solo para mover</span></label>
          <select id="destino" name="destino" @class(['input', 'is-invalid' => $errors->has('destino')])>
            <option value="">Elige el embarque destino…</option>
            @foreach ($destinos as $d)
              <option value="{{ $d->uuid }}" @selected(old('destino') === $d->uuid)>
                {{ $d->nombre }} · {{ $d->estaGuardada() ? 'guardado' : 'en captura' }}
              </option>
            @endforeach
          </select>
          @if (in_array($formulario, [null, 'piezas'], true))
            @error('destino') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
          @endif
        </div>
        <div class="field">
          <label for="motivo" class="label">Motivo</label>
          <textarea id="motivo" name="motivo" rows="2" maxlength="255" required minlength="5"
                    placeholder="Ej. Piezas en el embarque equivocado al cargar"
                    @class(['input', 'dialog-campo', 'is-invalid' => $errors->has('motivo') && in_array($formulario, [null, 'piezas'], true)])>{{ in_array($formulario, [null, 'piezas'], true) ? old('motivo') : '' }}</textarea>
          @if (in_array($formulario, [null, 'piezas'], true))
            @error('motivo') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
            @error('partidas') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
          @endif
        </div>
      </div>

      <div class="card-foot">
        <button type="submit" class="btn btn-danger-soft" formaction="{{ route('tarimas.partidas.retirar', $tarima) }}"
                onclick="return confirm('¿Retirar las piezas seleccionadas? Saldrán del inventario de la mudanza y sus N/S quedarán libres.')">
          <x-icon name="trash" /> Retirar seleccionadas
        </button>
        <button type="submit" class="btn btn-danger-soft" formaction="{{ route('tarimas.partidas.eliminar', $tarima) }}"
                onclick="return confirm('¿Eliminar las N/S seleccionadas? Se registraron por error: salen de la mudanza, sus N/S quedan libres y queda constancia en los movimientos.')">
          <x-icon name="x" /> Eliminar N/S
        </button>
        <button type="submit" class="btn btn-primary">
          <span class="spinner" aria-hidden="true"></span>
          <x-icon name="arrow-right" class="btn-icon" /> Mover seleccionadas
        </button>
      </div>
    @endif
  </form>

  @if ($modelos->isNotEmpty())
    <div class="correccion-ns">
      <form method="POST" action="{{ route('tarimas.partidas.agregar', $tarima) }}" class="card" data-loading>
        @csrf
        <input type="hidden" name="_form" value="agregar">
        <div class="card-head"><div>
          <h2 class="card-title"><x-icon name="plus" /> Agregar N/S a este modelo</h2>
          <p class="card-desc">Una pieza que faltó registrar. Recibe el siguiente consecutivo y solo se imprime su etiqueta.</p>
        </div></div>
        <div class="card-body">
          <div class="field">
            <label for="ag-modelo" class="label">Modelo (SKU)</label>
            <select id="ag-modelo" name="modelo" class="input" required>
              @foreach ($modelos as $m)
                <option value="{{ $m->id }}" @selected((int) old('modelo') === $m->id)>{{ $m->sku }} · {{ $m->descripcion_mostrada }}</option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="ag-ns" class="label">N/S</label>
            <input id="ag-ns" name="numero_serie" value="{{ $formulario === 'agregar' ? old('numero_serie') : '' }}" class="input input-mono" required maxlength="150"
                   autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="next" placeholder="Escanea o escribe la N/S">
          </div>
          <div class="field">
            <label for="ag-motivo" class="label">Motivo</label>
            <input id="ag-motivo" name="motivo" value="{{ $formulario === 'agregar' ? old('motivo') : '' }}" class="input" required minlength="5" maxlength="255" placeholder="Ej. Pieza sin escanear al armar el embarque">
          </div>
          @if ($formulario === 'agregar')
            @foreach (['modelo', 'numero_serie', 'sku', 'motivo', 'partidas'] as $campo)
              @error($campo) <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
            @endforeach
          @endif
        </div>
        <div class="card-foot">
          <button type="submit" class="btn btn-primary"><span class="spinner" aria-hidden="true"></span><x-icon name="plus" class="btn-icon" /> Agregar N/S</button>
        </div>
      </form>

      @php $conNs = $tarima->partidas->whereNotNull('numero_serie_id'); @endphp
      @if ($conNs->isNotEmpty())
        <form method="POST" action="{{ route('tarimas.partidas.editar', $tarima) }}" class="card" data-loading data-editar-partida>
          @csrf
          @method('PUT')
          <input type="hidden" name="_form" value="editar">
          <div class="card-head"><div>
            <h2 class="card-title"><x-icon name="pencil" /> Editar N/S</h2>
            <p class="card-desc">Corrige el SKU o el número de serie. La pieza conserva su consecutivo y solo se reimprime su etiqueta.</p>
          </div></div>
          <div class="card-body">
            <div class="field">
              <label for="ed-partida" class="label">Pieza</label>
              <select id="ed-partida" name="partida" class="input" required data-partida>
                <option value="">Elige la pieza…</option>
                @foreach ($conNs as $p)
                  <option value="{{ $p->id }}" data-sku="{{ $p->sku }}" data-ns="{{ $p->numero_serie }}" @selected((int) old('partida') === $p->id)>#{{ $p->consecutivo }} · {{ $p->sku }} · {{ $p->numero_serie }}</option>
                @endforeach
              </select>
            </div>
            <div class="field">
              <label for="ed-sku" class="label">SKU</label>
              <input id="ed-sku" name="sku" value="{{ $formulario === 'editar' ? old('sku') : '' }}" list="ed-skus" class="input input-mono" required maxlength="100"
                     autocomplete="off" autocapitalize="characters" spellcheck="false" data-partida-sku>
              <datalist id="ed-skus">@foreach ($modelos as $m)<option value="{{ $m->sku }}">@endforeach</datalist>
            </div>
            <div class="field">
              <label for="ed-ns" class="label">N/S</label>
              <input id="ed-ns" name="numero_serie" value="{{ $formulario === 'editar' ? old('numero_serie') : '' }}" class="input input-mono" required maxlength="150"
                     autocomplete="off" autocapitalize="characters" spellcheck="false" data-partida-ns>
            </div>
            <div class="field">
              <label for="ed-motivo" class="label">Motivo</label>
              <input id="ed-motivo" name="motivo" value="{{ $formulario === 'editar' ? old('motivo') : '' }}" class="input" required minlength="5" maxlength="255" placeholder="Ej. N/S mal capturada a mano">
            </div>
            @if ($formulario === 'editar')
              @foreach (['partida', 'sku', 'numero_serie', 'motivo', 'partidas'] as $campo)
                @error($campo) <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
              @endforeach
            @endif
          </div>
          <div class="card-foot">
            <button type="submit" class="btn btn-primary"><span class="spinner" aria-hidden="true"></span><x-icon name="check" class="btn-icon" /> Guardar cambio</button>
          </div>
        </form>
      @endif
    </div>
  @endif

  <section class="card">
    <div class="card-head">
      <div>
        <h2 class="card-title"><x-icon name="clock" /> Movimientos</h2>
        <p class="card-desc">Cambios de embarque, retiros y correcciones de N/S que tocaron este embarque.</p>
      </div>
    </div>
    @if ($movimientos->isEmpty())
      <div class="card-body"><p class="hint">Sin correcciones.</p></div>
    @else
      <div class="table-wrap">
        <table class="table table-stack">
          <thead><tr><th>Fecha</th><th>Pieza</th><th>Movimiento</th><th>Motivo</th><th>Usuario</th></tr></thead>
          <tbody>
            @foreach ($movimientos as $m)
              <tr>
                <td class="nowrap" data-label="Fecha">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                <td data-label="Pieza"><span class="mono">#{{ $m->partida->consecutivo }} · {{ $m->partida->ns_mostrada }}</span></td>
                <td data-label="Movimiento">
                  {{ MovimientoPartida::NOMBRES[$m->tipo] ?? $m->tipo }}
                  @if ($m->tipo === MovimientoPartida::CAMBIO_TARIMA)
                    · {{ $m->origen?->codigo }} → {{ $m->destino?->codigo }}
                  @endif
                  @if ($m->detalle)<br><span class="hint mono">{{ $m->detalle }}</span>@endif
                </td>
                <td data-label="Motivo">{{ $m->motivo }}</td>
                <td data-label="Usuario">{{ $m->usuario?->name ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>

</main>
@endsection

@push('scripts')
<script>
  // Editar N/S: al elegir la pieza se llenan su SKU y su N/S actuales.
  (function () {
    const form = document.querySelector('[data-editar-partida]');
    if (!form) return;
    const pieza = form.querySelector('[data-partida]');
    pieza.addEventListener('change', () => {
      const op = pieza.selectedOptions[0];
      form.querySelector('[data-partida-sku]').value = op?.dataset.sku || '';
      form.querySelector('[data-partida-ns]').value = op?.dataset.ns || '';
    });
    if (pieza.value && !form.querySelector('[data-partida-ns]').value) pieza.dispatchEvent(new Event('change'));
  })();

  // Con el escáner: cada N/S leída (texto + Enter) marca su pieza y limpia el campo
  // para la siguiente. El Enter nunca envía el formulario.
  (function () {
    const campo = document.querySelector('[data-marcar-escaneo]');
    if (!campo) return;
    campo.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const valor = campo.value.trim().toLowerCase();
      const visibles = Array.from(document.querySelectorAll('#tablaPartidas tbody tr')).filter(f => !f.hidden);
      // "SN-1" también coincide con "SN-10": gana la N/S idéntica.
      const fila = visibles.length === 1
        ? visibles[0]
        : visibles.find(f => f.querySelector('td:nth-child(4)').textContent.trim().toLowerCase() === valor);
      if (!fila) { campo.select(); return; }
      fila.querySelector('input[type="checkbox"]').checked = true;
      fila.classList.add('is-marcada');
      campo.value = '';
      campo.dispatchEvent(new Event('input'));
    });
  })();
</script>
@endpush
