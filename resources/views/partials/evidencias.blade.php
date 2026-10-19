{{-- Galería de evidencias con su huella. Recibe $evidencias. --}}
  @if ($evidencias->isNotEmpty())
    <section class="card">
      <div class="card-head"><div><h2 class="card-title"><x-icon name="upload" /> Evidencias</h2><p class="card-desc">Cada archivo tiene su huella SHA-256; si cambiara, el sistema no lo entrega.</p></div></div>
      <div class="card-body evidencias">
        @foreach ($evidencias as $e)
          <a class="evidencia" href="{{ route('evidencias.ver', $e) }}" target="_blank" rel="noopener" title="SHA-256 {{ $e->sha256 }}">
            @if (in_array($e->mime, ['image/jpeg', 'image/png', 'image/webp'], true))
              <img src="{{ route('evidencias.ver', $e) }}" alt="" loading="lazy">
            @else
              <span class="tile"><x-icon name="file-text" /></span>
            @endif
            <span class="evidencia-nombre">{{ $e->nombre_original }}</span>
            <span class="hint">{{ $e->subidaPor?->name }} · {{ $e->created_at->format('d/m H:i') }}</span>
          </a>
        @endforeach
      </div>
    </section>
  @endif
