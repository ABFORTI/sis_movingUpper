<!DOCTYPE html>
  <html lang="es">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="csrf-token" content="{{ csrf_token() }}">
      <meta name="theme-color" content="#1C156D">
      <meta name="mobile-web-app-capable" content="yes">
      <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
      
      <title>@yield('title', 'Escaneo') · MovingUpper</title>
      
      <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
      <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/favicon-32.png') }}">
      <link rel="apple-touch-icon" href="{{ asset('images/brand/apple-touch-icon.png') }}">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&family=Space+Grotesk:wght@500;600&display=swap">
      <link rel="stylesheet" href="{{ asset('css/wms.css') }}?v={{ @filemtime(public_path('css/wms.css')) }}">
    </head>
    
    <body class="@yield('body_class')" data-sw="{{ asset('sw.js') }}">
      @unless (View::hasSection('pantalla_completa'))
        @auth
          @include('layouts.navigation')
        @endauth
        <div class="flash" role="status" aria-live="polite">
          @if (session('ok'))
            <div class="alert alert-success" data-alert data-autohide="1">
              <x-icon name="check-circle" />
              <div class="alert-body"><p class="alert-title">{{ session('ok') }}</p></div>
              <button type="button" class="alert-close" aria-label="Cerrar"><x-icon name="x" class="icon-sm" /></button>
            </div>
          @endif
          @if (session('warn'))
          <div class="alert alert-warn" data-alert>
            <x-icon name="alert" />
            <div class="alert-body"><p class="alert-title">{{ session('warn') }}</p></div>
            <button type="button" class="alert-close" aria-label="Cerrar"><x-icon name="x" class="icon-sm" /></button>
          </div>
          @endif
          @if ($errors->any())
          <div class="alert alert-danger" data-alert role="alert">
            <x-icon name="alert" />
            <div class="alert-body">
              @if ($errors->count() === 1)
                <p class="alert-title">{{ $errors->first() }}</p>
              @else
                <p class="alert-title">Revisa los siguientes datos</p>
                <ul>
                  @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                  @endforeach
                </ul>
              @endif
            </div>
            <button type="button" class="alert-close" aria-label="Cerrar"><x-icon name="x" class="icon-sm" /></button>
          </div>
          @endif
        </div>
      @endunless
    
      @yield('content')

      @auth
        {{-- Motivo de anular, suspender, cancelar…: lo usan los formularios con data-motivo (ui.js). --}}
        <dialog id="dlgMotivo" class="dialog" aria-labelledby="dlgMotivoTitulo">
          <form method="dialog">
            <h2 class="dialog-title" id="dlgMotivoTitulo" data-titulo></h2>
            <p class="dialog-text">Queda registrado con tu nombre y la hora.</p>
            <label for="dlgMotivoCampo" class="label" style="margin-top:14px;">Motivo</label>
            <textarea id="dlgMotivoCampo" class="input dialog-campo" rows="3" minlength="5" maxlength="255" required
                      placeholder="Ej. Embarque creado por error"></textarea>
            <div class="dialog-actions">
              <button type="submit" value="cancelar" class="btn btn-secondary" formnovalidate>Cancelar</button>
              <button type="submit" value="ok" class="btn btn-danger" data-confirmar>Confirmar</button>
            </div>
          </form>
        </dialog>
      @endauth
        <script src="{{ asset('js/ui.js') }}?v={{ @filemtime(public_path('js/ui.js')) }}"></script>
      @stack('scripts')
    </body>
  </html>
