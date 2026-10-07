@extends('layouts.app')

@section('title', $usuario->exists ? 'Editar usuario' : 'Nuevo usuario')

@section('content')
<main class="page page-xs">

  <nav class="breadcrumb" aria-label="Ruta">
    <a href="{{ route('usuarios.index') }}">Usuarios</a>
    <x-icon name="chevron-right" />
    <span>{{ $usuario->exists ? $usuario->name : 'Nuevo usuario' }}</span>
  </nav>

  <div class="page-head">
    <div>
      <h1 class="page-title">{{ $usuario->exists ? 'Editar usuario' : 'Nuevo usuario' }}</h1>
      <p class="page-sub">{{ $usuario->exists ? 'Actualiza los datos, el rol o el acceso de esta persona.' : 'Crea una cuenta y asígnale un rol para darle acceso.' }}</p>
    </div>
  </div>

  <form method="POST" action="{{ $usuario->exists ? route('usuarios.update', $usuario) : route('usuarios.store') }}" class="card" data-loading>
    @csrf
    @if ($usuario->exists)
      @method('PUT')
    @endif

    <div class="card-head">
      <div>
        <h2 class="card-title"><x-icon name="user" /> Datos de la cuenta</h2>
      </div>
    </div>
    <div class="card-body">
      <div class="field">
        <label for="name" class="label">Nombre completo</label>
        <input id="name" name="name" value="{{ old('name', $usuario->name) }}" required autocomplete="name"
               placeholder="Ej. Ana López" @class(['input', 'is-invalid' => $errors->has('name')])>
        @error('name') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
      </div>

      <div class="field-row" style="margin-top:16px;">
        <div class="field">
          <label for="email" class="label">Correo</label>
          <div class="control">
            <x-icon name="mail" class="icon-sm" />
            <input id="email" type="email" name="email" value="{{ old('email', $usuario->email) }}" required
                   autocomplete="off" spellcheck="false" placeholder="nombre@empresa.com"
                   @class(['input', 'is-invalid' => $errors->has('email')])>
          </div>
          @error('email') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
        </div>

        <div class="field">
          <label for="rol" class="label">Rol</label>
          <select id="rol" name="rol" @class(['input', 'is-invalid' => $errors->has('rol')])>
            @foreach ($roles as $rol)
              <option value="{{ $rol->slug }}" @selected(old('rol', $usuario->roles->first()?->slug ?? 'operador') === $rol->slug)>{{ $rol->name }}</option>
            @endforeach
          </select>
          @error('rol') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
        </div>
      </div>
    </div>

    <div class="card-head" style="border-top:1px solid var(--border);padding-top:20px;">
      <div>
        <h2 class="card-title"><x-icon name="lock" /> Seguridad</h2>
        <p class="card-desc">{{ $usuario->exists ? 'Deja la contraseña vacía para conservar la actual.' : 'Mínimo 8 caracteres, con letras y números.' }}</p>
      </div>
    </div>
    <div class="card-body">
      <div class="field-row">
        <div class="field">
          <label for="password" class="label">Contraseña @if ($usuario->exists)<span class="optional">opcional</span>@endif</label>
          <div class="control">
            <input id="password" type="password" name="password" autocomplete="new-password" @required(! $usuario->exists)
                   @class(['input', 'has-action', 'is-invalid' => $errors->has('password')])>
            <button type="button" class="control-action" data-toggle-password="password" aria-label="Mostrar contraseña" aria-pressed="false">
              <x-icon name="eye" class="icon-sm" data-eye />
              <x-icon name="eye-off" class="icon-sm" data-eye-off hidden />
            </button>
          </div>
          @error('password') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
        </div>

        <div class="field">
          <label for="password_confirmation" class="label">Confirmar contraseña</label>
          <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" class="input">
        </div>
      </div>

      <input type="hidden" name="is_active" value="0">
      <label class="switch-row" style="margin-top:20px;">
        <div>
          <strong>Usuario activo</strong>
          <span>Los usuarios inactivos no pueden iniciar sesión.</span>
        </div>
        <input type="checkbox" name="is_active" value="1" class="switch" @checked(old('is_active', $usuario->is_active))>
      </label>
    </div>

    <div class="card-foot">
      <a class="btn btn-ghost" href="{{ route('usuarios.index') }}">Cancelar</a>
      <button type="submit" class="btn btn-primary">
        <span class="spinner" aria-hidden="true"></span>
        <x-icon name="check" class="btn-icon" /> {{ $usuario->exists ? 'Guardar cambios' : 'Crear usuario' }}
      </button>
    </div>
  </form>

</main>
@endsection
