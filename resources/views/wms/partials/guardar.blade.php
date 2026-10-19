{{--
  Guardar: pasa el embarque de temporal al historial. Se habilita solo cuando
  ya se confirmó la impresión de la etiqueta de cada una de sus piezas
  (Tarima::listaParaGuardar en el servidor).
  tarimas.js lo habilita sin recargar y hace el guardado; el servidor vuelve a validar todo.
--}}
@php
  $impresa = $tarima->listaParaGuardar();
  $conAnomalia = $tarima->registros->flatMap->numerosSerie->filter->tieneAnomalia()->pluck('numero_serie');
@endphp

<span class="guardar-wrap" data-guardar-wrap
      @unless ($impresa) title="Primero imprime las etiquetas de las piezas de este embarque" @endunless>
  <button type="button" class="btn btn-primary btn-sm"
          data-guardar="{{ route('tarimas.guardar', $tarima) }}"
          data-uuid="{{ $tarima->uuid }}"
          data-nombre="{{ $tarima->nombre }}"
          data-anomalias="{{ $conAnomalia->join(', ') }}"
          @disabled(! $impresa)
          aria-describedby="guardar-ayuda-{{ $tarima->id }}">
    <span class="spinner" aria-hidden="true"></span>
    <x-icon name="check" class="btn-icon" /> <span data-guardar-texto>Guardar</span>
  </button>
  <span id="guardar-ayuda-{{ $tarima->id }}" class="sr-only">
    {{ $impresa ? 'Etiquetas impresas: ya puedes guardar el embarque.' : 'Disponible después de imprimir la etiqueta de cada pieza.' }}
  </span>
</span>


  