{{--
    Gelistirilmesi gereken konular (Dalga 17b). Beklenen: $weakTopics.

    YALNIZCA ACIK konular. "Gelistirilmesi gereken" listesi gecmisin degil
    BUGUNUN listesi; kapanmislari birakmak liste uzadikca ogrenciyi bogar.
    Koc gecmisi kendi ekraninda goruyor.

    TEK parca, iki ekran (ogrenci + veli): SS6.1-2 geregi ikisi de ayni
    kumeyi gorur.
--}}
@if($weakTopics->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header"><h4>Geliştirilmesi gereken konular</h4></div>
        <div class="card-body">
            @foreach($weakTopics as $konu)
                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <strong>{{ $konu->topic }}</strong>
                    <span class="text-muted">{{ $konu->subject?->name ?? 'Genel' }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif
