{{--
    Net gelisim grafigi (Dalga 12b). Beklenen: $examResults.

    SUNUCUDA URETILEN SATIR ICI SVG. Proje derleme adimi tasimiyor (Vite yok,
    stiller elle yazili); bir grafik kutuphanesini CDN'den cekmek yeni bir
    bagimlilik sinifi sokardi. Sunucuda uretmek ayrica test edilebilir kiliyor.

    Renkler SVG niteliginde (stroke/fill), CSS sinifi degil: seri sayisi
    veriden geliyor ve her ders icin app.css'e sinif eklemek gerekirdi.

    NEDENSELLIK IDDIASI YOK (SS7-C): calisma suresi buraya bindirilmiyor.
--}}
@php
    use App\Support\ChartPath;
    use App\Support\NetProgress;

    $grafikler = NetProgress::fromResults($examResults);

    // Cizim kutusu; disindaki bosluk eksen etiketleri icin.
    $genislik = 560;
    $yukseklik = 120;
    $renkler = ['#2563eb', '#16a34a', '#dc2626', '#d97706', '#7c3aed', '#0891b2', '#db2777'];
@endphp

@if($grafikler !== [])
    <h2>Net gelişimi</h2>

    @foreach($grafikler as $grafik)
        @php
            // Duz seri sifira bolunmesin diye alt/ust bir birim aciliyor.
            $altSinir = $grafik['min'] === $grafik['max'] ? $grafik['min'] - 1 : $grafik['min'];
            $ustSinir = $grafik['min'] === $grafik['max'] ? $grafik['max'] + 1 : $grafik['max'];
        @endphp

        <div class="card mb-3">
            <div class="card-header">
                <h4>{{ $grafik['type']->label() }} · {{ count($grafik['labels']) }} deneme</h4>
            </div>
            <div class="card-body">
                <svg viewBox="0 0 620 165" width="100%" height="180" role="img"
                     aria-label="{{ $grafik['type']->label() }} net gelişimi">
                    <g transform="translate(45,15)">
                        {{-- Ust ve alt sinir cizgileri --}}
                        <line x1="0" y1="0" x2="{{ $genislik }}" y2="0" stroke="#e5e7eb" stroke-width="1"></line>
                        <line x1="0" y1="{{ $yukseklik }}" x2="{{ $genislik }}" y2="{{ $yukseklik }}" stroke="#e5e7eb" stroke-width="1"></line>

                        <text x="-8" y="4" text-anchor="end" font-size="10" fill="#6b7280">{{ number_format($ustSinir, 1, ',', '.') }}</text>
                        <text x="-8" y="{{ $yukseklik + 4 }}" text-anchor="end" font-size="10" fill="#6b7280">{{ number_format($altSinir, 1, ',', '.') }}</text>

                        @foreach($grafik['series'] as $i => $seri)
                            @php
                                $renk = $renkler[$i % count($renkler)];
                                $noktalar = ChartPath::points($seri['points'], $altSinir, $ustSinir, $genislik, $yukseklik);
                            @endphp

                            @if($noktalar !== '')
                                <polyline points="{{ $noktalar }}" fill="none" stroke="{{ $renk }}"
                                          stroke-width="{{ $i === 0 ? 2.5 : 1.5 }}"></polyline>

                                @foreach(explode(' ', $noktalar) as $nokta)
                                    @php [$nx, $ny] = explode(',', $nokta); @endphp
                                    <circle cx="{{ $nx }}" cy="{{ $ny }}" r="{{ $i === 0 ? 3 : 2 }}" fill="{{ $renk }}"></circle>
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Ilk ve son denemenin adi; hepsini yazmak dar ekranda
                             ust uste binerdi. --}}
                        <text x="0" y="{{ $yukseklik + 20 }}" font-size="10" fill="#6b7280">{{ $grafik['labels'][0] }}</text>
                        <text x="{{ $genislik }}" y="{{ $yukseklik + 20 }}" text-anchor="end" font-size="10" fill="#6b7280">{{ $grafik['labels'][count($grafik['labels']) - 1] }}</text>
                    </g>
                </svg>

                <div class="d-flex gap-2 mt-2" style="flex-wrap: wrap;">
                    @foreach($grafik['series'] as $i => $seri)
                        <span class="text-muted" style="font-size: 0.8rem;">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:{{ $renkler[$i % count($renkler)] }};"></span>
                            {{ $seri['name'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
@endif
