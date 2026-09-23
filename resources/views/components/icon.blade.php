{{--
    Cizgi simge (Lucide, ISC): public/img/simgeler.svg. Renk metinden gelir
    (currentColor). Simge susar; adi yanindaki metin ya da dugmenin
    aria-label'i verir.
--}}
@props(['name'])
<svg {{ $attributes->class(['icon']) }} aria-hidden="true" focusable="false"><use href="{{ \App\Support\Asset::url('img/simgeler.svg') }}#{{ $name }}"/></svg>
