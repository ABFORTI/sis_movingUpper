
@props(['href' => null, 'empresa' => true, 'tono' => 'claro'])

@php $tag = $href ? 'a' : 'span'; @endphp

<{{ $tag }} @if ($href) href="{{ $href }}" aria-label="MovingUpper de Upper Logistics, ir al inicio" @endif
  {{ $attributes->class(['brand', 'brand-oscuro' => $tono === 'oscuro']) }}>
  <span class="brand-mark">
    <img src="{{ asset('images/brand/movingupper-mark.png') }}" alt="" width="218" height="132">
  </span>
  <span class="brand-name">Moving<span>Upper</span></span>
  @if ($empresa)
    <span class="brand-divider" aria-hidden="true"></span>
    <img class="brand-company" src="{{ asset('images/brand/upper-logistics.png') }}" alt="Upper Logistics" width="581" height="132">
  @endif
</{{ $tag }}>
