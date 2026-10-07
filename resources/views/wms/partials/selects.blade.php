<div class="field-stack">
  <div class="field">
    <label for="destino_id" class="label">Categoría</label>
    <select id="destino_id" name="destino_id" @class(['input', 'is-invalid' => $errors->has('destino_id')])>
      @foreach ($destinos as $destino)
        <option value="{{ $destino->id }}" @selected($destinoSeleccionado === $destino->id)>{{ $destino->codigo }} · {{ $destino->nombre }}</option>
      @endforeach
    </select>
  </div>

  <div class="field">
    <label for="tarima" class="label">Tarima</label>
    <select id="tarima" name="tarima" @class(['input', 'is-invalid' => $errors->has('tarima')]) @disabled($tarimas->isEmpty())>
      @forelse ($tarimas as $t)
        <option value="{{ $t->uuid }}" @selected($tarimaSeleccionada === $t->uuid)>{{ $t->nombre }}</option>
      @empty
        <option value="">Sin tarimas</option>
      @endforelse
    </select>
  </div>
</div>
