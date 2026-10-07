{{--
  Guardar: pasa la tarima de temporal al historial. Se habilita solo cuando
  Browser Print confirmó la impresión de su etiqueta (impresa_at en el servidor).
  tarimas.js lo habilita sin recargar y hace el guardado; el servidor vuelve a validar todo.
--}}
@php $impresa = $tarima->estaImpresa(); @endphp

<span class="guardar-wrap" data-guardar-wrap
      @unless ($impresa) title="Primero imprime la etiqueta de esta tarima" @endunless>
  <button type="button" class="btn btn-primary btn-sm"
          data-guardar="{{ route('tarimas.guardar', $tarima) }}"
          data-uuid="{{ $tarima->uuid }}"
          @disabled(! $impresa)
          aria-describedby="guardar-ayuda-{{ $tarima->id }}">
    <span class="spinner" aria-hidden="true"></span>
    <x-icon name="check" class="btn-icon" /> <span data-guardar-texto>Guardar</span>
  </button>
  <span id="guardar-ayuda-{{ $tarima->id }}" class="sr-only">
    {{ $impresa ? 'Etiqueta impresa: ya puedes guardar la tarima.' : 'Disponible después de imprimir la etiqueta.' }}
  </span>
</span>


  