@php
  $usuario = auth()->user();
  $iniciales = collect(preg_split('/\s+/', trim($usuario->name)))->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('');
@endphp

<header class="topbar">
  <div class="topbar-inner">
    <x-brand :href="route('wms')" />

    <nav class="nav" aria-label="Principal">
      <a href="{{ route('wms') }}" @class(['nav-link', 'is-active' => request()->routeIs('wms', 'etiquetas.*')])
         @if (request()->routeIs('wms')) aria-current="page" @endif>
        <x-icon name="scan" /><span>Escaneo</span>
      </a>
      <a href="{{ route('historial') }}" @class(['nav-link', 'is-active' => request()->routeIs('historial')])
         @if (request()->routeIs('historial')) aria-current="page" @endif>
        <x-icon name="clock" /><span>Historial</span>
      </a>
      <a href="{{ route('manuales.index') }}" @class(['nav-link', 'is-active' => request()->routeIs('manuales.*')])
         @if (request()->routeIs('manuales.*')) aria-current="page" @endif>
        <x-icon name="book" /><span>Manuales</span>
      </a>
      @if ($usuario->isAdmin())
        <a href="{{ route('usuarios.index') }}" @class(['nav-link', 'is-active' => request()->routeIs('usuarios.*')])
           @if (request()->routeIs('usuarios.*')) aria-current="page" @endif>
          <x-icon name="users" /><span>Usuarios</span>
        </a>
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
</header>
