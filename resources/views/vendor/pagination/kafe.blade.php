{{--
    Sayfalama (Dalga 30a). Laravel'in varsayilani Tailwind siniflariyla
    yazilmis; bu projenin CSS'i elle yazildigi icin oklar dev SVG olarak
    cikiyordu. AppServiceProvider bunu varsayilan yapar.
--}}
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Sayfalar">
        @if ($paginator->onFirstPage())
            <span class="page-link disabled">‹ Önceki</span>
        @else
            <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Önceki</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="page-link disabled">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page-link active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page-link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Sonraki ›</a>
        @else
            <span class="page-link disabled">Sonraki ›</span>
        @endif
    </nav>
@endif
