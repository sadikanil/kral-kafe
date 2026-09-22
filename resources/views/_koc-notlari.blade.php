{{--
    Paylasilan koc notlari (Dalga 14b).

    TEK parca, iki ekran: ogrenci ve veli AYNI kumeyi gorur - SS6.1-3
    "veliye ne gittigini ogrenci kendi panelinde gorur, gizli izleme yok".
    Ayri ayri yazilsaydi biri gun gelip digerinden fazlasini gosterirdi.

    Suzgec burada DEGIL, CoachNote::shared() scope'unda; sablonun
    gizlilik karari vermesi, bir gun baska bir sablonun onu unutmasi
    demekti.

    $coachNotes bos ise bolum hic cizilmez.
--}}
@if($coachNotes->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header"><h4>Koç notları</h4></div>
        <div class="card-body">
            @foreach($coachNotes as $not)
                <div class="session-card mb-2">
                    <div class="text-muted">
                        <span class="badge badge-info">{{ $not->kind->label() }}</span>
                        {{ $not->displayDate()->timezone(config('kafe.timezone'))->format('d.m.Y') }}
                        @if($not->author)· {{ $not->author->name }}@endif
                    </div>
                    <p class="mt-2 mb-0">{{ $not->body }}</p>
                </div>
            @endforeach
        </div>
    </div>
@endif
