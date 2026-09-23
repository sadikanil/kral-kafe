@extends('layouts.app')

@section('title', 'Çalışma - Kral Kafe')
@section('page-title', 'Çalışma · ' . $session->table->name)

{{--
    Calisma sayaci (Dalga 23) + esneme hatirlaticilari (Dalga 24).

    Sure SUNUCUDA hesaplanir (net: duraklamalar dusulur); sayfa yalnizca
    gorunumu tazeler. Sekme kapansa da oturum surer.

    Hatirlatici takvimi App\Support\BreakReminders'tan gelir; JS yalnizca
    araliksiz calisma o ana gelince pencereyi acar.
--}}
@php
    $calisiyor = $pause === null;
@endphp

@section('content')
    <div class="session-card mb-3 text-center" id="sayac"
         data-net="{{ $session->minutesSoFar() * 60 }}"
         data-calisiyor="{{ $calisiyor ? 1 : 0 }}"
         data-araliksiz="{{ $session->continuousSeconds() }}"
         data-kalan="{{ $pause?->secondsLeft() ?? '' }}">

        <div class="d-flex align-items-center gap-2 mb-2" style="justify-content: center;">
            @if($calisiyor)
                <span class="live-dot"></span><strong>Çalışıyorsun</strong>
            @else
                <strong>⏸ {{ $pause->kind->label() }}</strong>
            @endif
        </div>

        <div class="session-timer js-net">
            {{ sprintf('%02d:%02d', intdiv($session->minutesSoFar(), 60), $session->minutesSoFar() % 60) }}
        </div>
        <p class="text-muted mb-3">Net çalışma · molalar sayılmaz</p>

        @if(! $calisiyor && $pause->secondsLeft() !== null)
            <p class="mb-3">Molanın bitmesine <strong class="js-kalan">{{ gmdate('i:s', $pause->secondsLeft()) }}</strong></p>
        @endif

        @if($calisiyor)
            <div class="d-flex gap-2 mb-2" style="justify-content: center; flex-wrap: wrap;">
                <form action="{{ route('session.pause') }}" method="POST">
                    @csrf <input type="hidden" name="tur" value="pause">
                    <button type="submit" class="btn btn-secondary btn-lg">⏸ Duraklat</button>
                </form>
                <form action="{{ route('session.pause') }}" method="POST" id="molaFormu">
                    @csrf <input type="hidden" name="tur" value="break">
                    <button type="submit" class="btn btn-warning btn-lg">☕ 15 dk mola</button>
                </form>
                <form action="{{ route('session.pause') }}" method="POST">
                    @csrf <input type="hidden" name="tur" value="lunch">
                    <button type="submit" class="btn btn-warning btn-lg">🍽 Öğle arası (1 saat)</button>
                </form>
            </div>
            <p class="text-muted" style="font-size:.85rem">Öğle arasında kafeden çıkabilirsin.</p>
        @else
            <form action="{{ route('session.resume') }}" method="POST" class="mb-2" id="devamFormu">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg btn-block">▶ Devam et</button>
            </form>
        @endif
    </div>

    {{-- Calisma kaydi (Dalga 28). Oturumun dersi son kayittan gelir. --}}
    <div class="card mb-3">
        <div class="card-header"><h4>✅ Ne bitirdin?</h4></div>
        <div class="card-body">
            <form action="{{ route('session.logs.store') }}" method="POST" class="log-form">
                @csrf
                <select name="subject_id" class="form-control" aria-label="Ders">
                    <option value="">Genel</option>
                    @foreach($subjects as $ders)
                        <option value="{{ $ders->id }}" @selected((int) old('subject_id', $session->subject_id) === $ders->id)>{{ $ders->name }}</option>
                    @endforeach
                </select>
                <input type="number" name="amount" class="form-control" min="1" max="10000" inputmode="numeric"
                       placeholder="200" value="{{ old('amount') }}" aria-label="Sayı" required>
                <select name="unit" class="form-control" aria-label="Birim">
                    @foreach(\App\Enums\StudyUnit::cases() as $birim)
                        <option value="{{ $birim->value }}" @selected(old('unit') === $birim->value)>{{ $birim->label() }}</option>
                    @endforeach
                </select>
                <input type="text" name="note" class="form-control log-note" maxlength="120"
                       placeholder="Not (isteğe bağlı)" value="{{ old('note') }}" aria-label="Not">
                <button type="submit" class="btn btn-primary">+ Ekle</button>
            </form>
            @if($errors->any())
                <p class="text-danger mt-2 mb-0">{{ $errors->first() }}</p>
            @endif

            @if($logs->isNotEmpty())
                <p class="mt-3 mb-2"><strong>Bugün:</strong>
                    {{ collect(\App\Models\StudyLog::totals($logs))->map(fn ($adet, $birim) => "$adet $birim")->implode(' · ') }}
                </p>
                <ul class="log-list">
                    @foreach($logs as $kayit)
                        <li>
                            <span class="text-muted">{{ $kayit->created_at->timezone(config('kafe.timezone'))->format('H:i') }}</span>
                            <span class="log-label">{{ $kayit->label() }} ✓
                                @if($kayit->note)<small class="text-muted">— {{ $kayit->note }}</small>@endif
                            </span>
                            @if($kayit->session?->ended_at === null)
                                <form action="{{ route('session.logs.destroy', $kayit) }}" method="POST"
                                      onsubmit="return confirm('Bu kaydı silmek istiyor musun?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-secondary" aria-label="Sil">✕</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-muted mt-3 mb-0">Bitirdiğin her şeyi buraya yaz: "200 soru tarih" gibi.</p>
            @endif
        </div>
    </div>

    <form action="{{ route('session.end') }}" method="POST" onsubmit="return confirm('Bugünkü çalışmayı bitirmek istiyor musun?')">
        @csrf
        <button type="submit" class="btn btn-danger btn-block">Çalışmayı bitir</button>
    </form>

    {{-- Hatirlatici penceresi (Dalga 24) --}}
    <div class="reminder" id="hatirlatici" hidden>
        <div class="reminder-box">
            <h3 class="js-baslik"></h3>
            <p class="js-metin"></p>
            <div class="d-flex gap-2" style="justify-content: center; flex-wrap: wrap;">
                <button type="button" class="btn btn-warning js-mola" hidden>☕ 15 dk mola ver</button>
                <button type="button" class="btn btn-primary js-devam" hidden>▶ Devam et</button>
                <button type="button" class="btn btn-secondary js-tamam">Tamam</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    const kutu = document.getElementById('sayac');
    const hatirlaticilar = @json($reminders);
    const yuklendi = Date.now();
    const calisiyor = kutu.dataset.calisiyor === '1';
    const net0 = Number(kutu.dataset.net);
    const araliksiz0 = Number(kutu.dataset.araliksiz);
    const kalan0 = kutu.dataset.kalan === '' ? null : Number(kutu.dataset.kalan);

    // Sayfa acildiginda zamani gecmis olanlar tekrar gosterilmez.
    let siradaki = hatirlaticilar.findIndex(h => h.at > araliksiz0);
    let molaBittiGosterildi = false;

    const pencere = document.getElementById('hatirlatici');
    function goster(baslik, metin, secenek) {
        pencere.querySelector('.js-baslik').textContent = baslik;
        pencere.querySelector('.js-metin').textContent = metin;
        pencere.querySelector('.js-mola').hidden = secenek !== 'mola';
        pencere.querySelector('.js-devam').hidden = secenek !== 'devam';
        pencere.hidden = false;
        if (navigator.vibrate) { navigator.vibrate([200, 100, 200]); }
    }
    pencere.querySelector('.js-tamam').onclick = () => { pencere.hidden = true; };
    pencere.querySelector('.js-mola').onclick = () => document.getElementById('molaFormu').submit();
    pencere.querySelector('.js-devam').onclick = () => document.getElementById('devamFormu').submit();

    const ikili = n => String(n).padStart(2, '0');

    function tik() {
        const gecen = Math.floor((Date.now() - yuklendi) / 1000);

        if (calisiyor) {
            const net = net0 + gecen;
            kutu.querySelector('.js-net').textContent = ikili(Math.floor(net / 3600)) + ':' + ikili(Math.floor(net % 3600 / 60));

            const araliksiz = araliksiz0 + gecen;
            if (siradaki !== -1 && siradaki < hatirlaticilar.length && araliksiz >= hatirlaticilar[siradaki].at) {
                const h = hatirlaticilar[siradaki];
                goster(h.title, h.text, h.kind === 'mola' ? 'mola' : null);
                siradaki++;
            }
        } else if (kalan0 !== null) {
            const kalan = Math.max(0, kalan0 - gecen);
            const el = kutu.querySelector('.js-kalan');
            if (el) { el.textContent = ikili(Math.floor(kalan / 60)) + ':' + ikili(kalan % 60); }

            // Mola bitince sure KENDILIGINDEN akmaz (karar, 23 Eyl): ogrenci
            // "Devam"a basana kadar calisma sayilmaz.
            if (kalan === 0 && !molaBittiGosterildi) {
                molaBittiGosterildi = true;
                goster('Mola bitti ⏰', 'Hazırsan devam et. Sen basana kadar süre durur.', 'devam');
            }
        }
    }

    tik();
    setInterval(tik, 1000);
})();
</script>
@endpush
