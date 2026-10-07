@extends('layouts.app')

@php
  use App\Enums\VisibilidadManual;
  use App\Models\Manual;

  $editando = $manual->exists;
  $visibilidad = old('visible_para', $manual->visible_para?->value);
  $maxMb = intdiv(Manual::TAMANO_MAXIMO_KB, 1024);
@endphp

@section('title', $editando ? 'Editar manual' : 'Nuevo manual')

@section('content')
<main class="page page-xs">

  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route('manuales.index') }}">Manuales</a>
    <x-icon name="chevron-right" />
    <span>{{ $editando ? $manual->nombre : 'Nuevo manual' }}</span>
  </nav>

  <div class="page-head">
    <div>
      <h1 class="page-title">{{ $editando ? 'Editar manual' : 'Nuevo manual' }}</h1>
      <p class="page-sub">{{ $editando ? 'Cambia el nombre, quién puede verlo o reemplaza el PDF.' : 'Sube un PDF y elige qué rol podrá consultarlo.' }}</p>
    </div>
  </div>

  <form method="POST" action="{{ $editando ? route('manuales.update', $manual) : route('manuales.store') }}"
        enctype="multipart/form-data" class="card" data-loading>
    @csrf
    @if ($editando)
      @method('PUT')
    @endif

    <div class="card-head">
      <div>
        <h2 class="card-title"><x-icon name="file-text" /> Datos del manual</h2>
      </div>
    </div>
    <div class="card-body field-stack">
      <div class="field">
        <label for="nombre" class="label">Nombre del manual</label>
        <input id="nombre" name="nombre" value="{{ old('nombre', $manual->nombre) }}" required maxlength="150" autocomplete="off"
               placeholder="Ej. Manual de operación de escáner" @class(['input', 'is-invalid' => $errors->has('nombre')])>
        @error('nombre') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>

      <div class="field">
        <span class="label" id="archivo-label">
          Archivo PDF
          @if ($editando)<span class="optional">opcional</span>@endif
        </span>

        @if ($editando)
          <div class="file-current">
            <span class="tile tile-muted"><x-icon name="file-text" /></span>
            <div style="min-width:0;flex:1;">
              <div class="manual-title">{{ $manual->nombreDescarga() }}</div>
              <div class="manual-meta">PDF actual · {{ $manual->tamanoLegible() }} · {{ $manual->updated_at->format('d/m/Y H:i') }}</div>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('manuales.ver', $manual) }}" target="_blank" rel="noopener"><x-icon name="external" /> Ver</a>
          </div>
        @endif

        <label @class(['file-drop', 'is-invalid' => $errors->has('archivo')]) data-file-drop data-max-kb="{{ Manual::TAMANO_MAXIMO_KB }}">
          <input type="file" name="archivo" accept="application/pdf,.pdf" aria-labelledby="archivo-label" @required(! $editando)>
          <span class="tile"><x-icon name="upload" /></span>
          <span class="file-drop-text">
            <strong data-file-name>{{ $editando ? 'Reemplazar con otro PDF' : 'Selecciona o arrastra el PDF' }}</strong>
            <span data-file-hint>Solo PDF, máximo {{ $maxMb }} MB.</span>
          </span>
        </label>
        @error('archivo') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>
    </div>

    <div class="card-head" style="border-top:1px solid var(--border);padding-top:20px;">
      <div>
        <h2 class="card-title" id="visible-label"><x-icon name="eye" /> Visible para</h2>
        <p class="card-desc">Los administradores siempre pueden ver todos los manuales.</p>
      </div>
    </div>
    <div class="card-body">
      <div class="option-cards" role="radiogroup" aria-labelledby="visible-label">
        @foreach (VisibilidadManual::cases() as $opcion)
          <label class="option-card">
            <input type="radio" name="visible_para" value="{{ $opcion->value }}" @checked($visibilidad === $opcion->value) required>
            <span class="option-text">
              <strong>{{ $opcion->etiqueta() }}</strong>
              <span>{{ $opcion->descripcion() }}</span>
            </span>
            <span class="option-roles" aria-hidden="true">
              @foreach ($opcion->roles() as $rol)
                <span class="badge badge-muted">{{ ucfirst($rol) }}</span>
              @endforeach
            </span>
          </label>
        @endforeach
      </div>
      @error('visible_para') <p class="field-error" style="margin-top:8px;"><x-icon name="alert" /> {{ $message }}</p> @enderror
    </div>

    <div class="card-foot">
      <a class="btn btn-ghost" href="{{ route('manuales.index') }}">Cancelar</a>
      <button type="submit" class="btn btn-primary">
        <span class="spinner" aria-hidden="true"></span>
        <x-icon name="check" class="btn-icon" /> {{ $editando ? 'Guardar cambios' : 'Publicar manual' }}
      </button>
    </div>
  </form>

</main>
@endsection
