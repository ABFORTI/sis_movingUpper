@extends('layouts.app')

@php use App\Models\Incidencia; @endphp

@section('title', 'Nueva incidencia')

@section('content')
<main class="page page-xs">

  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route('incidencias.index') }}">Incidencias</a>
    <x-icon name="chevron-right" />
    <span>Nueva</span>
  </nav>

  <div class="page-head">
    <div>
      <h1 class="page-title">Nueva incidencia</h1>
      <p class="page-sub">En la {{ $mudanza->titulo }}. Describe lo que encontraste; el supervisor le da seguimiento.
        Las N/S que no están en el inventario se marcan desde el escaneo, al confirmar el aviso.</p>
    </div>
  </div>

  <form method="POST" action="{{ route('incidencias.store') }}" enctype="multipart/form-data" class="card" data-loading>
    @csrf
    <div class="card-body">
      <div class="field">
        <label for="tipo" class="label">Tipo</label>
        <select id="tipo" name="tipo" required @class(['input', 'is-invalid' => $errors->has('tipo')])>
          @foreach (Incidencia::TIPOS as $clave => $nombre)
            <option value="{{ $clave }}" @selected(old('tipo', $tipo ?? Incidencia::DANIO) === $clave)>{{ $nombre }}</option>
          @endforeach
        </select>
        <p class="hint">«No encontrado en inventario» se registra para una sola pieza guardada (escanea su N/S abajo).</p>
        @error('tipo') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>

      <div class="field">
        <label for="titulo" class="label">Título</label>
        <input id="titulo" name="titulo" value="{{ old('titulo') }}" required minlength="5" maxlength="150"
               placeholder="Ej. Refrigerador con puerta golpeada" @class(['input', 'is-invalid' => $errors->has('titulo')])>
        @error('titulo') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>

      <div class="field">
        <label for="descripcion" class="label">Descripción <span class="optional">opcional</span></label>
        <textarea id="descripcion" name="descripcion" rows="3" maxlength="5000" class="input dialog-campo"
                  placeholder="Qué se encontró, dónde, y cualquier dato útil">{{ old('descripcion') }}</textarea>
      </div>

      <div class="field">
        <label for="piezas" class="label">Piezas afectadas <span class="optional">escanea N/S o escribe consecutivos, una por renglón</span></label>
        <textarea id="piezas" name="piezas" rows="3" maxlength="5000" autocapitalize="characters" spellcheck="false"
                  placeholder="SN-2026-00003&#10;145" @class(['input', 'dialog-campo', 'input-mono', 'is-invalid' => $errors->has('piezas')])>{{ old('piezas', $piezas) }}</textarea>
        @error('piezas') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
        <p class="hint">Solo piezas ya guardadas. Si el producto aún no se registra (o no se encontró), descríbelo arriba.</p>
      </div>

      @include('incidencias.partials.archivos')

      <input type="hidden" name="bloquea_traslado" value="0">
      <label class="switch-row" style="margin-top:16px;">
        <div>
          <strong>Bloquear el traslado de estas piezas</strong>
          <span>Mientras siga abierta, no se podrán cargar al tráiler.</span>
        </div>
        <input type="checkbox" name="bloquea_traslado" value="1" class="switch" @checked(old('bloquea_traslado', true))>
      </label>
    </div>

    <div class="card-foot">
      <a class="btn btn-ghost" href="{{ route('incidencias.index') }}">Cancelar</a>
      <button type="submit" class="btn btn-primary">
        <span class="spinner" aria-hidden="true"></span>
        <x-icon name="check" class="btn-icon" /> Registrar incidencia
      </button>
    </div>
  </form>

</main>
@endsection
