@if ($paginator->hasPages())
  <nav class="pager" role="navigation" aria-label="Paginación">
    @if ($paginator->onFirstPage())
      <span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true"><x-icon name="arrow-left" /> Anterior</span>
    @else
      <a class="btn btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="arrow-left" /> Anterior</a>
    @endif

    <span class="pager-pages">
      @foreach ($elements as $element)
        @if (is_string($element))
          <span class="pager-gap">{{ $element }}</span>
        @endif
        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <span class="pager-page is-current" aria-current="page">{{ $page }}</span>
            @else
              <a class="pager-page" href="{{ $url }}">{{ $page }}</a>
            @endif
          @endforeach
        @endif
      @endforeach
    </span>

    @if ($paginator->hasMorePages())
      <a class="btn btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente <x-icon name="arrow-right" /></a>
    @else
      <span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">Siguiente <x-icon name="arrow-right" /></span>
    @endif
  </nav>
@endif
