@php
  $series = $registro->numerosSerie;
  $marcadas = $series->filter->tieneAnomalia();
  $abierto = (int) old('registro_panel') === $registro->id || (int) session('panel') === $registro->id;
  $acciones = "ns-acciones-{$registro->id}";
  $listaSkus = "ns-skus-{$tarima->id}";
@endphp

<details class="ns-editor" data-ns-editor @if ($abierto) open @endif>
  <summary class="ns-editor-toggle">
    <x-icon name="pencil" class="icon-sm" />
    <span>Editar números de serie</span>
    <span class="badge badge-muted"><span data-total-registro>{{ $series->count() }}</span> N/S</span>
    @if ($marcadas->isNotEmpty())
      <span class="badge badge-warn">✕ {{ $marcadas->count() }} {{ $marcadas->count() === 1 ? 'dañada' : 'dañadas' }}</span>
    @endif
  </summary>

  <div class="ns-editor-body">
    @if ($abierto)
      @error('anomalia') <p class="field-error" role="alert"><x-icon name="alert" /> {{ $message }}</p> @enderror
      @error('nota') <p class="field-error" role="alert"><x-icon name="alert" /> {{ $message }}</p> @enderror
      @error('nueva_ns') <p class="field-error" role="alert"><x-icon name="alert" /> {{ $message }}</p> @enderror
      @error('nuevo_sku') <p class="field-error" role="alert"><x-icon name="alert" /> {{ $message }}</p> @enderror
    @endif

    @if ($bloqueado)
      <p class="hint ns-editor-aviso"><x-icon name="lock" class="icon-sm" /> Ya se imprimieron etiquetas de este embarque: aquí solo se marcan o desmarcan daños. Para otros cambios, vacía el embarque o corrígelo desde el Historial al guardarlo.</p>
    @else
      <form method="POST" action="{{ route('tarimas.ns.agregar', [$tarima, $registro]) }}" class="ns-agregar">
        @csrf
        <input type="hidden" name="registro_panel" value="{{ $registro->id }}">
        <label class="sr-only" for="ns-agregar-{{ $registro->id }}">Agregar N/S al SKU {{ $registro->sku }}</label>
        <div class="control">
          <x-icon name="plus" />
          <input id="ns-agregar-{{ $registro->id }}" name="nueva_ns" class="input input-mono" placeholder="Agregar N/S a {{ $registro->sku }}"
                 maxlength="150" autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="go" data-escaneo required>
          @include('wms.partials.teclado')
        </div>
        <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="plus" /> Agregar</button>
      </form>
    @endif

    <ul class="ns-lista" data-anomalias-lista>
      @foreach ($series as $ns)
        @include('wms.partials.editar-ns-fila', ['ns' => $ns])
      @endforeach
    </ul>
    <template data-ns-plantilla>
      @include('wms.partials.editar-ns-fila', ['ns' => null])
    </template>

    <form method="POST" action="{{ route('tarimas.anomalias', $tarima) }}" id="{{ $acciones }}" class="ns-acciones">
      @csrf
      <input type="hidden" name="registro_panel" value="{{ $registro->id }}">
      <div class="ns-acciones-campos">
        <div class="control">
          <x-icon name="scan" />
          <input name="numero_serie" class="input input-mono" placeholder="Escanea la N/S dañada o marca las casillas"
                 aria-label="Escanea la N/S dañada" autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="done" data-escaneo>
          @include('wms.partials.teclado')
        </div>
        <textarea name="nota" class="input" rows="1" maxlength="1000" aria-label="¿Qué tiene? (opcional)"
                  placeholder="¿Qué tiene? Opcional: carcasa golpeada, falta tapa…">{{ $abierto ? old('nota') : '' }}</textarea>
      </div>
      <div class="ns-acciones-botones">
        <button type="submit" name="accion" value="marcar" class="btn btn-secondary btn-sm"><x-icon name="alert" /> Marcar dañado</button>
        <button type="submit" name="accion" value="quitar" class="btn btn-ghost btn-sm"><x-icon name="x" /> Quitar marca</button>
        @unless ($bloqueado)
          <button type="submit" name="accion" value="eliminar" class="btn btn-danger-soft btn-sm" data-confirmar-eliminar>
            <x-icon name="trash" /> Eliminar
          </button>
        @endunless
      </div>
    </form>
  </div>
</details>
