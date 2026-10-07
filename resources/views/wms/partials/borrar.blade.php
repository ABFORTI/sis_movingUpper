@php $esAdmin = auth()->user()->isAdmin(); @endphp

@if (empty($soloEliminar))
  <form method="POST" action="{{ route('tarimas.vaciar', $tarima) }}"
        onsubmit="return confirm('{{ $esAdmin ? "¿Vaciar {$tarima->nombre} DEFINITIVAMENTE? No se puede deshacer." : "¿Vaciar {$tarima->nombre}?" }}')">
    @csrf
    @method('DELETE')
    <button type="submit" class="btn btn-ghost btn-sm"><x-icon name="eraser" /> Vaciar</button>
  </form>
@endif

@if ($esAdmin)
  <form method="POST" action="{{ route('tarimas.destroy', $tarima) }}"
        onsubmit="return confirm('¿Eliminar {{ $tarima->nombre }} y todo su contenido DEFINITIVAMENTE?')">
    @csrf
    @method('DELETE')
    <button type="submit" class="btn btn-danger-soft btn-sm">
      <x-icon name="trash" /> Eliminar {{ $soloEliminar ?? false ? $tarima->nombre : '' }}
    </button>
  </form>
@endif
