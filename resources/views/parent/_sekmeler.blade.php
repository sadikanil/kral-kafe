{{--
    Velinin cocuk sayfalari arasinda sekmeler (UX turu, 23 Eyl). Eskiden ust
    barda dugmelerdi; telefonda iki satir tutuyordu. Beklenen: $student.
--}}
<nav class="page-tabs mb-3" aria-label="{{ $student->name }}">
    @foreach([
        ['parent.student', 'Özet'],
        ['parent.report', 'Haftalık rapor'],
        ['parent.payments', 'Ödemeler'],
    ] as [$rota, $ad])
        <a href="{{ route($rota, $student) }}" class="page-tab {{ request()->routeIs($rota) ? 'active' : '' }}">{{ $ad }}</a>
    @endforeach
</nav>
