@extends('layouts.app')

@section('title', $impresora->exists ? 'Editar impresora' : 'Nueva impresora')

@section('content')
<main class="page page-xs">

  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route('impresoras.index') }}">Impresoras</a>
    <x-icon name="chevron-right" />
    <span>{{ $impresora->exists ? $impresora->nombre : 'Nueva impresora' }}</span>
  </nav>

  <div class="page-head">
    <div>
      <h1 class="page-title">{{ $impresora->exists ? 'Editar impresora' : 'Nueva impresora' }}</h1>
      <p class="page-sub">Cada handheld imprime en la impresora de esta lista que elija, buscándola por su MAC Bluetooth.</p>
    </div>
  </div>

  <form method="POST" action="{{ $impresora->exists ? route('impresoras.update', $impresora) : route('impresoras.store') }}" class="card" data-loading>
    @csrf
    @if ($impresora->exists)
      @method('PUT')
    @endif

    <div class="card-head">
      <div><h2 class="card-title"><x-icon name="printer" /> Datos de la impresora</h2></div>
    </div>
    <div class="card-body">
      <div class="field">
        <label for="nombre" class="label">Nombre para identificarla</label>
        <input id="nombre" name="nombre" value="{{ old('nombre', $impresora->nombre) }}" required maxlength="100"
               placeholder="Ej. ZQ620 Andén 1" @class(['input', 'is-invalid' => $errors->has('nombre')])>
        @error('nombre') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>

      <div class="field">
        <label for="mac" class="label">MAC Bluetooth</label>
        <input id="mac" name="mac" value="{{ old('mac', $impresora->mac) }}" required maxlength="20"
               autocapitalize="characters" spellcheck="false" autocomplete="off" placeholder="AC:3F:A4:CD:C5:15"
               @class(['input', 'input-mono', 'is-invalid' => $errors->has('mac')])>
        @error('mac') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
        <p class="hint">Con o sin separadores; se guarda como AA:BB:CC:DD:EE:FF.</p>
      </div>

      <div class="field">
        <label for="nombre_bluetooth" class="label">Nombre Bluetooth <span class="optional">opcional</span></label>
        <input id="nombre_bluetooth" name="nombre_bluetooth" value="{{ old('nombre_bluetooth', $impresora->nombre_bluetooth) }}" maxlength="100"
               spellcheck="false" autocomplete="off" placeholder="Ej. XXZKJ203401234"
               @class(['input', 'input-mono', 'is-invalid' => $errors->has('nombre_bluetooth')])>
        @error('nombre_bluetooth') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
        <p class="hint">Exactamente como aparece en la lista Bluetooth. Si lo escribes, la búsqueda de Chrome solo muestra esta impresora.</p>
      </div>

      <div class="field">
        <label for="ubicacion" class="label">Ubicación <span class="optional">opcional</span></label>
        <input id="ubicacion" name="ubicacion" value="{{ old('ubicacion', $impresora->ubicacion) }}" maxlength="150"
               placeholder="Ej. Andén norte" class="input">
      </div>

      <input type="hidden" name="activa" value="0">
      <label class="switch-row" style="margin-top:20px;">
        <div>
          <strong>Activa</strong>
          <span>Las inactivas no aparecen para elegir en los equipos.</span>
        </div>
        <input type="checkbox" name="activa" value="1" class="switch" @checked(old('activa', $impresora->activa))>
      </label>

      <div class="note" style="margin-top:20px;">
        <x-icon name="info" class="icon-sm" />
        <p>
          <b>¿Dónde está la MAC?</b> Con la ZQ620 encendida, mantén presionado el botón de avance de papel unos segundos hasta que imprima
          la <b>etiqueta de configuración</b>: busca <i>BT ADDRESS</i> o <i>bluetooth.address</i>. También aparece en Zebra Printer Setup
          (<i>Conectividad → Bluetooth</i>) y en los ajustes Bluetooth de Android al tocar la impresora emparejada.
        </p>
      </div>
    </div>

    <div class="card-foot">
      <a class="btn btn-ghost" href="{{ route('impresoras.index') }}">Cancelar</a>
      <button type="submit" class="btn btn-primary">
        <span class="spinner" aria-hidden="true"></span>
        <x-icon name="check" class="btn-icon" /> {{ $impresora->exists ? 'Guardar cambios' : 'Registrar impresora' }}
      </button>
    </div>
  </form>

</main>
@endsection
