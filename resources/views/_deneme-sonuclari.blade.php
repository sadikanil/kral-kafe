{{--
    Deneme sonuclari listesi. Ogrenci ve veli panelinde AYNI (karar 2:
    profile islenen her sey veliye acik).

    1 Ekim 2026: liste kisa ozet; ders tablosu, siralamalar ve konu konu
    sonuclar detay sayfasinda (exams/result). Beklenen: $examResults ve
    $sonucAdresi (fn (ExamResult) => url).

    Siralama, gizlilik kurali 4'e (ogrenciler birbiriyle karsilastirilmaz)
    takilmiyor: bu, SINAVIN kendi verisi - sistemin urettigi bir kiyas degil.
--}}
@include('_net-grafigi')

@if($examResults->isNotEmpty())
    <h2 class="section-title">Deneme sonuçları</h2>

    <div class="card mb-3">
        <div class="card-body p-0">
            @foreach($examResults as $sonuc)
                @php
                    $ozet = collect([
                        $sonuc->event->exam_date->format('d.m.Y'),
                        $sonuc->score !== null ? 'Puan ' . number_format((float) $sonuc->score, 3, ',', '.') : null,
                        $sonuc->rank_institution ? 'Kurum ' . $sonuc->rank_institution . '.' . ($sonuc->total_institution ? '/' . $sonuc->total_institution : '') : null,
                        ! empty($sonuc->topics) ? count($sonuc->weakTopics()) . ' eksik konu' : null,
                    ])->filter()->implode(' · ');
                @endphp
                <a href="{{ $sonucAdresi($sonuc) }}" class="list-row">
                    <span class="list-row-main">
                        <span class="list-row-title">{{ $sonuc->event->title }}</span>
                        <span class="list-row-sub">{{ $ozet }}</span>
                    </span>
                    <span class="list-row-value"><strong>{{ number_format($sonuc->totalNet(), 2, ',', '.') }}</strong> net</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
