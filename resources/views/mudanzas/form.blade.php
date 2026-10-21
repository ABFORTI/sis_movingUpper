@extends('layouts.app')

@php
  $movimientos = collect(old('movimientos', $mudanza->exists
      ? $mudanza->movimientos->map(fn ($m) => ['origen' => $m->origen->codigo, 'destino' => $m->destino->codigo])->all()
      : []));
  $movimientos = $movimientos->push(['origen' => '', 'destino' => ''])->values();
@endphp

@section('title', $mudanza->exists ? "Editar {$mudanza->folio}" : 'Nueva mudanza')

@section('content')
<main class="page page-xs">

  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route('mudanzas.index') }}">Mudanzas</a>
    <x-icon name="chevron-right" />
    <span>{{ $mudanza->exists ? $mudanza->folio : 'Nueva mudanza' }}</span>
  </nav>

  <div class="page-head">
    <div>
      <h1 class="page-title">{{ $mudanza->exists ? "Editar {$mudanza->folio}" : 'Nueva mudanza' }}</h1>
      <p class="page-sub">{{ $mudanza->exists ? 'Actualiza los datos del servicio.' : 'Se crea como borrador con el siguiente folio. Actívala desde la lista para empezar a capturar.' }}</p>
    </div>
  </div>

  <form method="POST" action="{{ $mudanza->exists ? route('mudanzas.update', $mudanza) : route('mudanzas.store') }}" class="card" data-loading>
    @csrf
    @if ($mudanza->exists)
      @method('PUT')
    @endif

    <div class="card-body">
      <div class="field">
        <label for="cliente" class="label">Cliente</label>
        <input id="cliente" name="cliente" value="{{ old('cliente', $mudanza->cliente) }}" required maxlength="150"
               placeholder="Ej. Euroquip" @class(['input', 'is-invalid' => $errors->has('cliente')])>
        @error('cliente') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>

      <fieldset class="field" style="margin-top:16px;">
        <legend class="label">Movimientos entre almacenes</legend>
        <p class="hint" style="margin-bottom:8px;">Una mudanza puede incluir varios (p. ej. 58 → 3 y 5 → 7). Cada almacén conserva su propio inventario. Deja vacío el último renglón si no lo necesitas.</p>
        <div class="movimientos" data-movimientos>
          @foreach ($movimientos as $i => $m)
            <div class="movimiento-fila" data-movimiento>
              <input name="movimientos[{{ $i }}][origen]" value="{{ $m['origen'] }}" maxlength="50" list="lista-almacenes"
                     placeholder="Origen (ej. 58)" aria-label="Almacén de origen {{ $i + 1 }}"
                     @class(['input', 'input-mono', 'is-invalid' => $errors->has("movimientos.$i.origen")])>
              <span class="flecha" aria-hidden="true"><x-icon name="arrow-right" class="icon-sm" /></span>
              <input name="movimientos[{{ $i }}][destino]" value="{{ $m['destino'] }}" maxlength="50" list="lista-almacenes"
                     placeholder="Destino (ej. 3)" aria-label="Almacén de destino {{ $i + 1 }}"
                     @class(['input', 'input-mono', 'is-invalid' => $errors->has("movimientos.$i.destino")])>
              <button type="button" class="icon-btn" data-quitar-movimiento aria-label="Quitar movimiento {{ $i + 1 }}" title="Quitar"><x-icon name="x" class="icon-sm" /></button>
            </div>
          @endforeach
        </div>
        <datalist id="lista-almacenes">
          @foreach ($almacenes as $a)
            <option value="{{ $a->codigo }}">{{ $a->nombre }}</option>
          @endforeach
        </datalist>
        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px;" data-agregar-movimiento><x-icon name="plus" /> Agregar movimiento</button>
        @if ($error = $errors->first('movimientos') ?: $errors->first('movimientos.*'))
          <p class="field-error"><x-icon name="alert" /> {{ $error }}</p>
        @endif
      </fieldset>

      <div class="field" style="margin-top:16px;">
        <label for="notas" class="label">Notas <span class="optional">opcional</span></label>
        <textarea id="notas" name="notas" rows="3" maxlength="500" @class(['input', 'is-invalid' => $errors->has('notas')])>{{ old('notas', $mudanza->notas) }}</textarea>
        @error('notas') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>
    </div>

    <div class="card-foot">
      <a class="btn btn-ghost" href="{{ route('mudanzas.index') }}">Cancelar</a>
      <button type="submit" class="btn btn-primary">
        <span class="spinner" aria-hidden="true"></span>
        <x-icon name="check" class="btn-icon" /> {{ $mudanza->exists ? 'Guardar cambios' : 'Crear mudanza' }}
      </button>
    </div>
  </form>

</main>
@endsection

@push('scripts')
<script>
  (function () {
    const lista = document.querySelector('[data-movimientos]');
    if (!lista) return;
    const renumerar = () => lista.querySelectorAll('[data-movimiento]').forEach((fila, i) => {
      fila.querySelectorAll('input').forEach(input => { input.name = input.name.replace(/movimientos\[\d+\]/, `movimientos[${i}]`); });
    });
    document.querySelector('[data-agregar-movimiento]').addEventListener('click', () => {
      const nueva = lista.lastElementChild.cloneNode(true);
      nueva.querySelectorAll('input').forEach(i => { i.value = ''; i.classList.remove('is-invalid'); });
      lista.appendChild(nueva);
      renumerar();
      nueva.querySelector('input').focus();
    });
    lista.addEventListener('click', e => {
      const boton = e.target.closest('[data-quitar-movimiento]');
      if (!boton) return;
      const fila = boton.closest('[data-movimiento]');
      if (lista.children.length > 1) fila.remove(); else fila.querySelectorAll('input').forEach(i => { i.value = ''; });
      renumerar();
    });
  })();
</script>
@endpush
