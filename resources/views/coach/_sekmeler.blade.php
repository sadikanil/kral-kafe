{{--
    Kocun ogrenci sayfalari arasinda sekmeler (UX turu, 23 Eyl). Eskiden
    ust barda dort dugmeydi; telefonda iki satir tutuyordu. Simgesiz: dort
    sekme 390 px'e ancak boyle sigiyor. Beklenen: $student.
--}}
<nav class="page-tabs mb-3" aria-label="{{ $student->name }}">
    @foreach([
        ['coach.plan.show', 'Plan'],
        ['coach.notes.index', 'Notlar'],
        ['coach.report', 'Rapor'],
        ['coach.topics.index', 'Konular'],
    ] as [$rota, $ad])
        <a href="{{ route($rota, $student) }}" class="page-tab {{ request()->routeIs($rota) ? 'active' : '' }}">{{ $ad }}</a>
    @endforeach
</nav>
