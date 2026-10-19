{{-- El embarque define el almacén de origen, y con él el inventario del que salen los SKU. --}}
<div class="field">
  <label for="tarima" class="label">Embarque</label>
  <select id="tarima" name="tarima" @class(['input', 'is-invalid' => $errors->has('tarima')]) @disabled($tarimas->isEmpty()) data-sku-tarima>
    @forelse ($tarimas as $t)
      <option value="{{ $t->uuid }}" @selected($tarimaSeleccionada === $t->uuid)>
        {{ $t->nombre }}{{ $t->movimiento ? ' · Almacén '.$t->movimiento->nombre : ' · sin almacén' }}
      </option>
    @empty
      <option value="">Sin embarques</option>
    @endforelse
  </select>
  @error('tarima') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
</div>
