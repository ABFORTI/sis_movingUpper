@extends('layouts.app')

@section('title', 'Inventarios')

@php
  $supervision = auth()->user()->esSupervision();
  $obs = $ultimaCarga?->observaciones ?? [];
@endphp

@section('content')
<main class="page">

  <div class="page-head">
    <div>
      <p class="eyebrow"><x-icon name="sheet" class="icon-sm" /> Operación</p>
      <h1 class="page-title">Inventarios por almacén</h1>
      <p class="page-sub">
        Cada almacén tiene su propio inventario. Las N/S que se escanean se validan contra el inventario del almacén de origen de su embarque.
        @if ($mudanza) Mudanza {{ $mudanza->titulo }}: {{ $mudanza->rutas }}. @endif
      </p>
    </div>
  </div>

  @if ($ultimaCarga)
    <section class="card">
      <div class="card-head">
        <div>
          <h2 class="card-title"><x-icon name="check-circle" /> Resultado de la carga · {{ $ultimaCarga->almacen->etiqueta }}</h2>
          <p class="card-desc">{{ $ultimaCarga->archivo_nombre }}</p>
        </div>
      </div>
      <div class="card-body">
        <div class="meta-grid" style="margin-top:0;">
          <div class="meta-item"><span>Filas leídas</span><b>{{ number_format($ultimaCarga->filas_leidas) }}</b></div>
          <div class="meta-item"><span>Productos importados</span><b>{{ number_format($ultimaCarga->filas_importadas) }}</b></div>
          <div class="meta-item"><span>Filas omitidas</span><b>{{ number_format($ultimaCarga->filas_omitidas) }}</b></div>
        </div>
        @if (! empty($obs['errores']))
          <div class="alert alert-danger" style="margin-top:16px;">
            <x-icon name="alert" />
            <div class="alert-body">
              <p class="alert-title">{{ $obs['total_errores'] }} {{ $obs['total_errores'] === 1 ? 'fila no se importó' : 'filas no se importaron' }}</p>
              <ul class="carga-detalle">@foreach ($obs['errores'] as $e)<li>{{ $e }}</li>@endforeach</ul>
              @if ($obs['total_errores'] > count($obs['errores']))<p class="hint">Se muestran las primeras {{ count($obs['errores']) }}.</p>@endif
            </div>
          </div>
        @endif
        @if (! empty($obs['avisos']))
          <div class="alert alert-warn" style="margin-top:16px;">
            <x-icon name="alert" />
            <div class="alert-body">
              <p class="alert-title">{{ $obs['total_avisos'] }} {{ $obs['total_avisos'] === 1 ? 'aviso' : 'avisos' }}: revisa estos registros</p>
              <ul class="carga-detalle">@foreach ($obs['avisos'] as $a)<li>{{ $a }}</li>@endforeach</ul>
            </div>
          </div>
        @endif
        @if (! empty($obs['columnas']))
          <p class="hint" style="margin-top:12px;">Columnas reconocidas: {{ collect($obs['columnas'])->map(fn ($celda, $campo) => "{$campo} ({$celda})")->join(', ') }}.</p>
        @endif
      </div>
    </section>
  @endif

  @if ($supervision)
    <details class="card" @if ($errors->hasAny(['almacen', 'archivo']) || $almacenes->filter->inventarioVigente->isEmpty()) open @endif>
      <summary class="card-head summary-card"><h2 class="card-title"><x-icon name="upload" /> Cargar inventario (Excel)</h2></summary>
      <form method="POST" action="{{ route('inventarios.store') }}" enctype="multipart/form-data" class="card-body" data-loading>
        @csrf
        @if ($almacenes->isEmpty())
          <p class="hint">Todavía no hay almacenes. Se registran al agregar los movimientos de una mudanza.</p>
        @else
          <div class="field">
            <label for="almacen" class="label">Almacén al que corresponde el archivo</label>
            <select id="almacen" name="almacen" required @class(['input', 'is-invalid' => $errors->has('almacen')])>
              <option value="">Elige el almacén…</option>
              @foreach ($almacenes as $a)
                <option value="{{ $a->id }}" @selected($almacenSeleccionado === $a->id)>
                  {{ $a->etiqueta }}{{ $a->rutas ? " · {$a->rutas}" : '' }}{{ $a->inventarioVigente ? ' · ya tiene inventario' : '' }}
                </option>
              @endforeach
            </select>
            @error('almacen') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
          </div>

          <div class="field">
            <label for="archivo" class="label">Archivo <span class="optional">.xlsx, .xls o .csv</span></label>
            <input id="archivo" name="archivo" type="file" required accept=".xlsx,.xls,.csv" @class(['input', 'is-invalid' => $errors->has('archivo')]) style="padding:8px;height:auto;">
            @error('archivo') <p class="field-error"><x-icon name="alert" /> {{ $message }}</p> @enderror
            <p class="hint">
              Encabezados reconocidos (en cualquier orden): <b>SKU</b> (obligatorio), <b>Modelo</b>, <b>Descripción</b>,
              <b>Número de serie del fabricante</b>, <b>Número de serie</b> y <b>Número de serie de lote</b>.
              Las filas sin número de serie se importan solo con su SKU.
              Si el archivo trae columna <b>Almacén</b>, debe coincidir con el elegido.
            </p>
          </div>

          <input type="hidden" name="reemplazar" value="0">
          <label class="switch-row" style="margin-top:12px;">
            <div>
              <strong>Reemplazar el inventario actual de ese almacén</strong>
              <span>El anterior se conserva como histórico; lo ya escaneado no cambia. Solo afecta al almacén elegido.</span>
            </div>
            <input type="checkbox" name="reemplazar" value="1" class="switch" @checked(old('reemplazar'))>
          </label>

          <button type="submit" class="btn btn-primary btn-block" style="margin-top:16px;">
            <span class="spinner" aria-hidden="true"></span><x-icon name="upload" class="btn-icon" /> Cargar inventario
          </button>
        @endif
      </form>
    </details>
  @endif

  @if ($almacenes->isNotEmpty())
    {{-- Producto que no viene en el Excel del inventario: se da de alta solo con su SKU, sin N/S. --}}
    @php $erroresAlta = $errors->altaSku; @endphp
    <details class="card" @if ($erroresAlta->any()) open @endif>
      <summary class="card-head summary-card"><h2 class="card-title"><x-icon name="plus" /> Dar de alta un SKU</h2></summary>
      <form method="POST" action="{{ route('inventarios.skus.store') }}" class="card-body alta-sku" data-loading>
        @csrf
        <p class="hint" style="margin-top:0;">Para un producto que todavía no viene en el inventario del almacén. Solo se registra el SKU: no se crean N/S ni piezas. Al escanear sus N/S quedan pendientes de revisión.</p>
        <div class="field">
          <label for="alta-almacen" class="label">Almacén</label>
          <select id="alta-almacen" name="almacen" required @class(['input', 'is-invalid' => $erroresAlta->has('almacen')])>
            @foreach ($almacenes as $a)
              <option value="{{ $a->id }}" @selected((int) old('almacen', $almacenSeleccionado ?: $almacenes->firstWhere('es_origen')?->id) === $a->id)>{{ $a->etiqueta }}{{ $a->es_origen ? ' · se escanea aquí' : '' }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="alta-sku" class="label">SKU</label>
          <div class="control">
            <x-icon name="tag" />
            <input id="alta-sku" name="sku" value="{{ $erroresAlta->any() ? old('sku') : '' }}" required maxlength="100"
                   @class(['input', 'input-lg', 'input-mono', 'is-invalid' => $erroresAlta->has('sku')])
                   placeholder="Escribe o escanea el SKU" autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="next">
          </div>
        </div>
        <div class="field">
          <label for="alta-descripcion" class="label">Descripción <span class="optional">opcional</span></label>
          <input id="alta-descripcion" name="descripcion" value="{{ $erroresAlta->any() ? old('descripcion') : '' }}" maxlength="255"
                 class="input" placeholder="Ej. Compresor 160 L" autocomplete="off" enterkeyhint="done">
        </div>
        @foreach ($erroresAlta->all() as $mensaje)
          <p class="field-error" role="alert"><x-icon name="alert" /> {{ $mensaje }}</p>
        @endforeach
        <button type="submit" class="btn btn-primary btn-block"><span class="spinner" aria-hidden="true"></span><x-icon name="plus" class="btn-icon" /> Dar de alta</button>
      </form>
    </details>
  @endif

  <div class="section-head" style="margin-top:8px;">
    <h2 class="section-title">Almacenes</h2>
  </div>

  @if ($almacenes->isEmpty())
    <div class="card"><div class="empty">
      <div class="empty-icon"><x-icon name="inbox" class="icon-xl" /></div>
      <p class="empty-title">Sin almacenes</p>
      <p class="empty-text">Los almacenes se registran al capturar los movimientos de una mudanza (origen → destino).</p>
    </div></div>
  @else
    <div class="almacenes">
      @foreach ($almacenes as $a)
        @php $carga = $a->inventarioVigente; @endphp
        <article class="almacen-card">
          <div class="btn-group" style="justify-content:space-between;">
            <h3>{{ $a->etiqueta }}</h3>
            @if ($carga)
              <span class="badge badge-success"><x-icon name="check" class="icon-sm" /> Con inventario</span>
            @else
              <span class="badge badge-warn"><x-icon name="alert" class="icon-sm" /> Sin inventario</span>
            @endif
          </div>
          @if ($a->rutas)<p class="hint">Movimientos: <span class="mono">{{ $a->rutas }}</span>{{ $a->es_origen ? ' · se escanea aquí' : '' }}</p>@endif
          @if ($carga)
            <p class="hint">{{ number_format($carga->filas_importadas) }} productos · {{ $carga->archivo_nombre }}<br>
              Cargado {{ $carga->created_at->format('d/m/Y H:i') }} por {{ $carga->cargadaPor?->name ?? '—' }}</p>
            <a class="btn btn-secondary btn-sm" href="{{ route('inventarios.exportar', $a) }}"><x-icon name="download" /> Excel del inventario</a>
          @elseif ($a->es_origen)
            <p class="hint">Sin inventario no se pueden registrar piezas de embarques de este almacén.</p>
          @endif
          @if ($a->skusAlta->isNotEmpty())
            <details class="skus-alta">
              <summary>{{ $a->skusAlta->count() }} {{ $a->skusAlta->count() === 1 ? 'SKU dado de alta' : 'SKU dados de alta' }} sin N/S</summary>
              <ul>
                @foreach ($a->skusAlta as $alta)
                  <li><span class="mono">{{ $alta->sku }}</span>@if ($alta->descripcion) · {{ $alta->descripcion }}@endif</li>
                @endforeach
              </ul>
            </details>
          @endif
        </article>
      @endforeach
    </div>
  @endif

</main>
@endsection
