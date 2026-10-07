@extends('layouts.app')

@section('title', 'Iniciar sesión')
@section('pantalla_completa', true)

@section('content')
<main class="auth">
  <aside class="auth-aside" aria-hidden="true">
    <x-brand :empresa="false" tono="oscuro" />

    <div>
      <h1 class="auth-headline">Mueve tu almacén <em>sin perder una sola pieza.</em></h1>
      <div class="auth-rule"><i></i><i></i></div>
      <ul class="features">
        <li class="feature">
          <span class="feature-icon"><x-icon name="scan" /></span>
          <div><strong>Escaneo continuo</strong><span>SKU y números de serie por tarima.</span></div>
        </li>
        <li class="feature">
          <span class="feature-icon"><x-icon name="qr" /></span>
          <div><strong>Etiquetas Zebra con QR</strong><span>Consulta verificada del contenido.</span></div>
        </li>
        <li class="feature">
          <span class="feature-icon"><x-icon name="sheet" /></span>
          <div><strong>Reportes en Excel</strong><span>Por tarima o de todo el inventario.</span></div>
        </li>
      </ul>
    </div>

    <div class="auth-foot">
      <span>© {{ date('Y') }} Upper Logistics</span>
      <span><x-icon name="shield" class="icon-sm" /> Acceso solo para personal autorizado</span>
    </div>
  </aside>

  <section class="auth-main">
    <div class="auth-form">

      <img class="auth-company" src="{{ asset('images/brand/upper-logistics.png') }}" alt="Upper Logistics" width="581" height="132">
      <x-brand class="auth-mobile-brand" />

      <h2 class="auth-title">Bienvenido</h2>
      <p class="auth-sub">Inicia sesión para continuar con tu operación.</p>

      @if ($errors->any())
        <div class="alert alert-danger" role="alert" style="margin-bottom:22px;">
          <x-icon name="alert" />
          <div class="alert-body">
            <p class="alert-title">No pudimos iniciar tu sesión</p>
            <p class="alert-text">{{ $errors->first() }}</p>
          </div>
        </div>
      @endif

      <form method="POST" action="{{ route('login') }}" data-loading novalidate>
        @csrf

        <div class="field">
          <label for="email" class="label">Correo electrónico</label>
          <div class="control">
            <x-icon name="mail" />
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   @class(['input', 'input-lg', 'is-invalid' => $errors->has('email')])
                   required autocomplete="username" inputmode="email" spellcheck="false"
                   placeholder="nombre@empresa.com"
                   @if (! old('email')) autofocus @endif
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
          </div>
          @error('email')
            <p class="field-error" id="email-error"><x-icon name="alert" /> {{ $message }}</p>
          @enderror
        </div>

        <div class="field">
          <label for="password" class="label">
            Contraseña
            <span class="caps" data-caps="password"><x-icon name="alert" /> Bloq Mayús activado</span>
          </label>
          <div class="control">
            <x-icon name="lock" />
            <input id="password" type="password" name="password"
                   @class(['input', 'input-lg', 'has-action', 'is-invalid' => $errors->has('password')])
                   required autocomplete="current-password" placeholder="Tu contraseña"
                   @if (old('email')) autofocus @endif
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            <button type="button" class="control-action" data-toggle-password="password"
                    aria-label="Mostrar contraseña" aria-pressed="false">
              <x-icon name="eye" data-eye />
              <x-icon name="eye-off" data-eye-off hidden />
            </button>
          </div>
          @error('password')
            <p class="field-error" id="password-error"><x-icon name="alert" /> {{ $message }}</p>
          @enderror
        </div>

        <div class="auth-row">
          <label class="check">
            <input type="checkbox" name="remember" class="checkbox" @checked(old('remember'))>
            Mantener sesión iniciada
          </label>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block">
          <span class="spinner" aria-hidden="true"></span>
          Iniciar sesión
          <x-icon name="arrow-right" class="btn-icon" />
        </button>
      </form>

      <p class="auth-help" style="text-align:center;margin-top:20px;">
        ¿No tienes acceso? Solicítalo a:
      </p>

      <div class="auth-trust">
        <x-icon name="mail" /> soporte@ab-forti.com
      </div>

      <p class="auth-legal">MovingUpper · Sistema de gestión de almacén de Upper Logistics</p>
    </div>
  </section>

</main>
@endsection
