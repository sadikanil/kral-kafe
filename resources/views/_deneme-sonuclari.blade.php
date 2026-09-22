{{--
    Deneme sonuclari listesi. Ogrenci ve veli panelinde AYNI (karar 2:
    profile islenen her sey veliye acik).

    Siralama, gizlilik kurali 4'e (ogrenciler birbiriyle karsilastirilmaz)
    takilmiyor: bu, SINAVIN kendi verisi - sistemin urettigi bir kiyas degil.
--}}
@include('_net-grafigi')

@if($examResults->isNotEmpty())
    <h2>Deneme sonuçları</h2>

    @foreach($examResults as $sonuc)
        <div class="session-card mb-2">
            <strong>{{ $sonuc->event->title }}</strong>
            <div class="text-muted">
                {{ $sonuc->event->exam_date->timezone(config('kafe.timezone'))->format('d.m.Y') }}
                · Toplam net {{ number_format($sonuc->totalNet(), 2, ',', '.') }}
            </div>

            <div class="mt-2">
                @foreach($sonuc->subjects as $satir)
                    <div class="text-muted">
                        {{ $satir->subject->name }}:
                        {{ $satir->correct }}D {{ $satir->wrong }}Y {{ $satir->blank }}B
                        · net {{ number_format($satir->net, 2, ',', '.') }}
                    </div>
                @endforeach
            </div>

            @php
                $siralamalar = collect([
                    'Kurum' => $sonuc->rankLabel('institution'),
                    'İlçe' => $sonuc->rankLabel('district'),
                    'İl' => $sonuc->rankLabel('city'),
                    'Türkiye' => $sonuc->rankLabel('country'),
                ])->filter();
            @endphp

            @if($siralamalar->isNotEmpty())
                <div class="mt-2">
                    @foreach($siralamalar as $etiket => $metin)
                        <div class="text-muted">{{ $etiket }}: {{ $metin }}</div>
                    @endforeach
                </div>
            @endif

            @if($sonuc->note)
                <div class="alert alert-info mt-2">{{ $sonuc->note }}</div>
            @endif
        </div>
    @endforeach
@endif
