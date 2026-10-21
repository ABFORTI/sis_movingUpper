{{-- Valores ya registrados que el navegador sugiere al escribir; se puede escribir uno nuevo sin restricción. --}}
@if (! empty($valores))
  <datalist id="{{ $id }}">
    @foreach ($valores as $valor)
      <option value="{{ $valor }}"></option>
    @endforeach
  </datalist>
@endif
