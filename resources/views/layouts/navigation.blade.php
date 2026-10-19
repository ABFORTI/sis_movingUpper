@php
  $usuario = auth()->user();
  $iniciales = collect(preg_split('/\s+/', trim($usuario->name)))->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('');
  $mudanzaEnCurso = app(\App\Support\MudanzaActiva::class)->obtener();
  $incidenciasAbiertas = $mudanzaEnCurso ? \App\Models\Incidencia::vigentes()->where('mudanza_id', $mudanzaEnCurso->id)->count() : 0;

  $seccion = fn (string $ruta, string $activa, string $icono, string $texto, int $contador = 0, string $contadorTexto = '') =>
      compact('ruta', 'activa', 'icono', 'texto', 'contador', 'contadorTexto');

  // Lo de todos los días: siempre en la barra (y en las pestañas del celular).
  $primarias = [
      $seccion('wms', 'wms,etiquetas.*', 'scan', 'Escaneo'),
      $seccion('historial', 'historial', 'clock', 'Historial'),
      $seccion('incidencias.index', 'incidencias.*', 'alert', 'Incidencias', $incidenciasAbiertas, 'abiertas'),
      $seccion('embarques.index', 'embarques.*', 'truck', 'Tráileres'),
  ];
  // En la barra de la computadora; en el celular van en "Más".
  $secundarias = [$seccion('inventarios.index', 'inventarios.*', 'sheet', 'Inventarios')];
  if ($usuario->esSupervision()) {
      $secundarias[] = $seccion('entregas.index', 'entregas.*', 'file-text', 'Reporte Diario');
  }
  // Administración y consulta: en "Más" (en la computadora solo si es admin).
  $terciarias = [$seccion('manuales.index', 'manuales.*', 'book', 'Manuales')];
  if ($usuario->isAdmin()) {
      $terciarias[] = $seccion('mudanzas.index', 'mudanzas.*', 'layers', 'Mudanzas');
      $terciarias[] = $seccion('usuarios.index', 'usuarios.*', 'users', 'Usuarios');
      $terciarias[] = $seccion('impresoras.index', 'impresoras.*', 'printer', 'Impresoras');
  } else {
      // El auditor crea y activa mudanzas.
      if ($usuario->hasRole(\App\Models\Role::AUDITOR)) {
          $terciarias[] = $seccion('mudanzas.index', 'mudanzas.*', 'layers', 'Mudanzas');
      }
      $secundarias = [...$secundarias, ...$terciarias];
      $terciarias = [];
  }

  // Celular: hasta 5 pestañas; si no caben todas, 4 + "Más".
  $todasEnPestanas = count($primarias) + count($secundarias) + count($terciarias) <= 5;
  $enMasMovil = $todasEnPestanas ? [] : [...$secundarias, ...$terciarias];
  $hayMas = $terciarias !== [] || $enMasMovil !== [];
  $activa = fn (array $s) => request()->routeIs(...explode(',', $s['activa']));
  $masActiva = collect([...$secundarias, ...$terciarias])->contains($activa);
  $avisosEnMas = collect($enMasMovil)->sum('contador');
@endphp

<header class="topbar">
  <div class="topbar-inner">
    <x-brand :href="route('wms')" />

    <nav class="nav" aria-label="Principal">
      @foreach ([...$primarias, ...$secundarias] as $i => $s)
        @php $esSecundaria = $i >= count($primarias); @endphp
        <a href="{{ route($s['ruta']) }}" title="{{ $s['texto'] }}"
           @class(['nav-link', 'is-active' => $activa($s), 'nav-solo-escritorio' => $esSecundaria && ! $todasEnPestanas])
           @if ($activa($s)) aria-current="page" @endif>
          <x-icon :name="$s['icono']" /><span class="nav-texto">{{ $s['texto'] }}</span>
          @if ($s['contador'])<b class="nav-contador" aria-label="{{ $s['contador'] }} {{ $s['contadorTexto'] }}">{{ $s['contador'] }}</b>@endif
        </a>
      @endforeach

      @if ($hayMas)
        <details @class(['nav-mas', 'nav-mas-solo-movil' => $terciarias === []]) data-nav-mas>
          <summary @class(['nav-link', 'is-active' => $masActiva]) title="Más secciones">
            <x-icon name="menu" /><span class="nav-texto">Más</span>
            @if ($avisosEnMas)<b class="nav-contador nav-solo-movil" aria-label="{{ $avisosEnMas }} pendientes">{{ $avisosEnMas }}</b>@endif
          </summary>
          <div class="nav-mas-panel">
            <p class="nav-mas-usuario">
              <span class="avatar">{{ $iniciales }}</span>
              <span><b>{{ $usuario->name }}</b><small>{{ $usuario->roles->first()?->name ?? 'Sin rol' }}</small></span>
            </p>
            @foreach ($enMasMovil as $s)
              @continue(in_array($s, $terciarias, true))
              <a href="{{ route($s['ruta']) }}" @class(['nav-mas-link', 'nav-solo-movil', 'is-active' => $activa($s)])>
                <x-icon :name="$s['icono']" /><span>{{ $s['texto'] }}</span>
                @if ($s['contador'])<b class="nav-contador">{{ $s['contador'] }}</b>@endif
              </a>
            @endforeach
            @foreach ($terciarias as $s)
              <a href="{{ route($s['ruta']) }}" @class(['nav-mas-link', 'is-active' => $activa($s)])>
                <x-icon :name="$s['icono']" /><span>{{ $s['texto'] }}</span>
              </a>
            @endforeach
          </div>
        </details>
      @endif
    </nav>

    <div class="user-menu">
      <div class="user-chip">
        <div class="user-meta">
          <div class="user-name">{{ $usuario->name }}</div>
          <div class="user-role">{{ $usuario->roles->first()?->name ?? 'Sin rol' }}</div>
        </div>
        <span class="avatar" title="{{ $usuario->name }}">{{ $iniciales }}</span>
      </div>
      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="icon-btn" title="Cerrar sesión" aria-label="Cerrar sesión">
          <x-icon name="logout" />
        </button>
      </form>
    </div>
  </div>

  {{-- Siempre visible, en su propia franja: evita registrar mercancía pensando que es otra mudanza. --}}
  <div @class(['mudanza-franja', 'is-captura' => $mudanzaEnCurso?->permiteCaptura()])
       title="{{ $mudanzaEnCurso ? "Movimientos: {$mudanzaEnCurso->rutas}" : '' }}">
    <x-icon name="truck" class="icon-sm" />
    <span class="mudanza-franja-texto">{{ $mudanzaEnCurso ? "{$mudanzaEnCurso->titulo} · {$mudanzaEnCurso->estado_nombre}" : 'Sin mudanza activa' }}</span>
  </div>
</header>
