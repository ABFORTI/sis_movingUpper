@extends('layouts.app')

@section('title', 'Usuarios')

@section('content')
<main class="page page-narrow">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="shield" class="icon-sm" /> Administración</p>
      <h1 class="page-title">Usuarios</h1>
      <p class="page-sub">Gestiona quién tiene acceso al sistema y con qué permisos.</p>
    </div>
    <a class="btn btn-accent" href="{{ route('usuarios.create') }}"><x-icon name="plus" /> Nuevo usuario</a>
  </div>

  <section class="card">
    @if ($usuarios->isEmpty())
      <div class="empty">
        <div class="empty-icon"><x-icon name="users" class="icon-xl" /></div>
        <p class="empty-title">Todavía no hay usuarios</p>
        <p class="empty-text">Crea el primer usuario para darle acceso al sistema.</p>
      </div>
    @else
      <div class="toolbar">
        <div class="control">
          <x-icon name="search" class="icon-sm" />
          <input type="search" class="input" placeholder="Buscar por nombre, correo o rol…"
                 data-filter="#tablaUsuarios" data-filter-count="#conteoUsuarios" aria-label="Buscar usuarios">
        </div>
        <span class="toolbar-count"><b id="conteoUsuarios">{{ $usuarios->count() }}</b> de {{ $usuarios->count() }}</span>
      </div>

      <div class="table-wrap">
        <table class="table table-stack" id="tablaUsuarios">
          <thead>
            <tr><th>Usuario</th><th>Rol</th><th>Estado</th><th class="actions"><span class="sr-only">Acciones</span></th></tr>
          </thead>
          <tbody>
            @foreach ($usuarios as $usuario)
              @php
                $iniciales = collect(preg_split('/\s+/', trim($usuario->name)))->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('');
                $rol = $usuario->roles->first();
              @endphp
              <tr @class(['is-inactive' => ! $usuario->is_active])>
                <td class="cell-person" data-label="Usuario">
                  <div class="person" style="text-align:left;">
                    <span class="avatar">{{ $iniciales }}</span>
                    <div style="min-width:0;">
                      <div class="person-name">{{ $usuario->name }}</div>
                      <div class="person-mail">{{ $usuario->email }}</div>
                    </div>
                  </div>
                </td>
                <td data-label="Rol">
                  <span @class(['badge', 'badge-dark' => $rol?->slug === 'admin', 'badge-muted' => ! in_array($rol?->slug, ['admin', 'auditor'], true)])>{{ $rol?->name ?? 'Sin rol' }}</span>
                </td>
                <td data-label="Estado">
                  @if ($usuario->is_active)
                    <span class="badge badge-success"><span class="dot"></span> Activo</span>
                  @else
                    <span class="badge badge-muted"><span class="dot"></span> Inactivo</span>
                  @endif
                </td>
                <td class="actions" data-label="">
                  <a class="btn btn-ghost btn-sm" href="{{ route('usuarios.edit', $usuario) }}"><x-icon name="pencil" /> Editar</a>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>

</main>
@endsection
