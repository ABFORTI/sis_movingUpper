@if (empty($soloAnular))
  <form method="POST" action="{{ route('tarimas.vaciar', $tarima) }}"
        onsubmit="return confirm('¿Vaciar {{ $tarima->nombre }}? El contenido se envía a la papelera.')">
    @csrf
    @method('DELETE')
    <button type="submit" class="btn btn-ghost btn-sm"><x-icon name="eraser" /> Vaciar</button>
  </form>
@endif

@can('anular', $tarima)
  {{--
    En captura, anular elimina el embarque (su número solo se reutiliza si es el
    último asignado). Ya guardado no se borra: conserva número y contenido y sale de los Excel.
  --}}
  <form method="POST" action="{{ route('tarimas.anular', $tarima) }}"
        data-motivo="{{ $tarima->estaGuardada()
            ? "¿Anular {$tarima->nombre}? Conserva su número y su contenido; no se puede deshacer."
            : "¿Anular {$tarima->nombre}? Se elimina el embarque con todo lo que tenga; no se puede deshacer." }}">
    @csrf
    <input type="hidden" name="motivo">
    <button type="submit" class="btn btn-danger-soft btn-sm">
      <x-icon name="trash" /> Anular {{ $soloAnular ?? false ? $tarima->nombre : '' }}
    </button>
  </form>
@endcan
