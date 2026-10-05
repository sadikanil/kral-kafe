@extends('layouts.app')

@section('title', $import->event->title . ' - Deneme Sonuçları')
@section('page-title', $import->event->title)

@section('page-actions')
    <a href="{{ route('admin.exam-imports.index') }}" class="btn btn-sm btn-secondary">← Deneme Sonuçları</a>
@endsection

{{--
    Aktarim kontrol sayfasi (1 Ekim 2026). Okuma bitene kadar betik
    "isle" ucunu tek tek cagirir (her istek tek sayfa; Vercel 60 sn).
    Sonra yonetici eslesmeleri kontrol eder ve yayinlar. "Kontrol et"
    satirlari (kismi ad) ve eslesmeyenler yayindan once cozulmeli.
--}}
@section('content')
    @php
        $meta = $import->meta ?? [];
        $katilim = $meta['participants'] ?? [];
        $okunuyor = $import->isProcessing();
        // Kuyruk (5 Ekim 2026): onunde okunan deneme varsa "Sirada".
        $sira = $import->queuePosition();
    @endphp

    <div class="card mb-3">
        <div class="card-body">
            <p class="mb-1">
                <span class="badge badge-{{ $import->statusBadge() }} js-durum">{{ $sira > 0 ? 'Sırada' : $import->statusLabel() }}</span>
                @if($import->provider)<span class="text-muted text-sm"> · {{ ['anthropic' => 'Claude', 'gemini' => 'Gemini', 'openai' => 'OpenAI'][$import->provider] ?? $import->provider }} ile okundu</span>@endif
            </p>
            @if(! empty($meta['exam_name']))
                <p class="text-muted text-sm mb-1">PDF'teki ad: {{ $meta['exam_name'] }}</p>
            @endif
            @if(array_filter($katilim))
                <p class="text-muted text-sm mb-1">
                    Katılım: kurum {{ $katilim['institution'] ?? '—' }} · ilçe {{ $katilim['district'] ?? '—' }}
                    · il {{ $katilim['city'] ?? '—' }} · genel {{ $katilim['country'] ?? '—' }}
                </p>
            @endif

            <div id="okuma" data-adres="{{ route('admin.exam-imports.process', $import) }}" data-okunuyor="{{ $okunuyor ? 1 : 0 }}">
                @if($okunuyor || $import->status === \App\Models\ExamImport::FAILED)
                    <p class="mb-2 js-ilerleme" aria-live="polite">
                        @if($sira > 0 && $progress['ahead'])
                            Sırada (önünde {{ $sira }} deneme): şu an «{{ $progress['ahead']['title'] }}» okunuyor. Bitince bu deneme kendiliğinden okunur.
                        @elseif($progress['total'] > 0)
                            Karneler: {{ $progress['done'] }} / {{ $progress['total'] }}
                        @else
                            Öğrenci listesi okunacak.
                        @endif
                    </p>
                @endif
                {{-- Okuma surerken kalan hata bir zaman asimi tekrari: uyari. --}}
                @if($import->error)
                    <div class="alert {{ $okunuyor ? 'alert-warning' : 'alert-danger' }} js-hata">{{ $import->error }}</div>
                @endif
                @if($okunuyor || $import->status === \App\Models\ExamImport::FAILED)
                    <form method="POST" action="{{ route('admin.exam-imports.process', $import) }}" class="js-devam-formu">
                        @csrf
                        <button type="submit" class="btn btn-primary">{{ $import->status === \App\Models\ExamImport::FAILED ? 'Devam et' : 'Okumaya başla' }}</button>
                    </form>
                @endif
            </div>

            <div class="d-flex gap-2 mt-2" style="flex-wrap: wrap;">
                @if($import->status === \App\Models\ExamImport::PUBLISHED)
                    <a href="{{ route('coach.exams.show', $import->event) }}" class="btn btn-sm btn-primary">Deneme analizini aç</a>
                @endif
                <a href="{{ route('admin.exam-imports.pdf', $import) }}" class="btn btn-sm btn-secondary" target="_blank">PDF'i aç</a>
                <form method="POST" action="{{ route('admin.exam-imports.destroy', $import) }}"
                      onsubmit="return confirm('Aktarım ve PDF silinsin mi? Yayınlanmış sonuçlar öğrencide kalır.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                </form>
            </div>
        </div>
    </div>

    @if($import->rows->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><h4>Öğrenciler ({{ $import->rows->count() }})</h4></div>
            <div class="card-body p-0">
                @foreach($import->rows as $satir)
                    @php
                        $veri = $satir->data ?? [];
                        $net = collect($veri['subjects'] ?? [])->sum(fn ($d) => $d['correct'] - $d['wrong'] / 4);
                        $eksik = collect($veri['topics'] ?? [])->filter(fn ($k) => \App\Support\ExamTopics::isWeak($k))->count();
                    @endphp
                    <div class="list-row" id="satir-{{ $satir->id }}">
                        <span class="list-row-main">
                            <span class="list-row-title">
                                {{ $satir->name }}
                                <span class="badge badge-{{ $satir->matchBadge() }}">{{ $satir->matchLabel() }}</span>
                            </span>
                            <span class="list-row-sub">
                                {{ $satir->class_label ?? '—' }}
                                · net {{ number_format($net, 2, ',', '.') }}
                                @if(isset($veri['score'])) · puan {{ number_format((float) $veri['score'], 3, ',', '.') }}@endif
                                @if($satir->card_page) · karne s. {{ $satir->card_page }}{{ $satir->card_read ? '' : ' (okunmadı)' }}@endif
                                @if($satir->card_read) · {{ $eksik }} eksik konu @endif
                                @if(! empty($veri['mismatch']))
                                    · <span class="text-danger">liste ile karne farklı ({{ collect($veri['mismatch'])->map(fn ($k) => $subjects[$k] ?? $k)->implode(', ') }}): PDF'e bakın</span>
                                @endif
                                @if($satir->student && ! $satir->student->entitlements()->examClub)
                                    · <span class="text-danger">deneme kulübü yok: sonucu panelinde görmez</span>
                                @endif
                            </span>
                            @if($import->status !== \App\Models\ExamImport::UPLOADED)
                                <form method="POST" action="{{ route('admin.exam-imports.rows.update', $satir) }}" class="d-flex gap-1 mt-1" style="flex-wrap: wrap;">
                                    @csrf @method('PATCH')
                                    <select name="student_id" class="form-control" style="max-width: 260px;" aria-label="{{ $satir->name }} için öğrenci">
                                        <option value="">— öğrenci seç —</option>
                                        @foreach($students as $ogrenci)
                                            <option value="{{ $ogrenci->id }}" @selected($satir->student_id === $ogrenci->id)>{{ $ogrenci->name }}</option>
                                        @endforeach
                                    </select>
                                    <span class="d-flex gap-1">
                                        <button type="submit" class="btn btn-sm btn-secondary">{{ $satir->match === \App\Models\ExamImportRow::SUGGESTED ? 'Onayla' : 'Kaydet' }}</button>
                                        <button type="submit" name="skip" value="1" class="btn btn-sm btn-secondary">Atla</button>
                                    </span>
                                </form>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        @if(in_array($import->status, [\App\Models\ExamImport::REVIEW, \App\Models\ExamImport::PUBLISHED], true))
            <form method="POST" action="{{ route('admin.exam-imports.publish', $import) }}"
                  onsubmit="return confirm('Sonuçlar öğrenci panellerine yazılsın ve bildirim gönderilsin mi?')">
                @csrf
                <button type="submit" class="btn btn-primary btn-block">
                    {{ $import->status === \App\Models\ExamImport::PUBLISHED ? 'Düzeltmeleri yeniden yayınla' : 'Sonuçları yayınla' }}
                </button>
            </form>
            @if($import->published_at)
                <p class="text-muted text-sm mt-1">Yayın: {{ $import->published_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}</p>
            @endif
        @endif
    @endif
@endsection

@push('scripts')
<script>
(function () {
    // Okuma dongusu: her istek tek adim (Vercel 60 sn). Hata olursa durur,
    // "Devam et" dugmesi kalir. Bitince sayfa yenilenir.
    const kutu = document.getElementById('okuma');
    if (!kutu || kutu.dataset.okunuyor !== '1') { return; }
    const ilerleme = kutu.querySelector('.js-ilerleme');
    const form = kutu.querySelector('.js-devam-formu');
    const jeton = document.querySelector('meta[name="csrf-token"]');

    // Kuyruk (5 Ekim 2026): okuma arka planda (ExamImportRunner); sayfa
    // yalnizca 4 sn'de bir durumu sorar (background) ve kopmus zinciri
    // canlandirir. Zincir kapaliysa (yerel) sayfa adimlari kendisi atar.
    // Gecici hatalar sunucuda tekrarlanir; mesaj okunurken gosterilir.
    function yaz(d) {
        if (!ilerleme) { return; }
        let metin = d.total > 0 ? 'Karneler: ' + d.done + ' / ' + d.total : 'Öğrenci listesi okunacak.';
        if (d.queue > 0 && d.ahead) {
            metin = 'Sırada (önünde ' + d.queue + ' deneme): şu an «' + d.ahead.title + '» okunuyor'
                + (d.ahead.total > 0 ? ' · ' + d.ahead.done + ' / ' + d.ahead.total : '') + '. Bitince bu deneme kendiliğinden okunur.';
        } else if (d.waiting) {
            metin += ' · başka bir okuma sürüyor, bekleniyor…';
        } else if (d.message) {
            metin += ' · ' + d.message;
        } else if (d.background) {
            metin += ' · arka planda okunuyor; sayfayı kapatabilirsin.';
        } else {
            metin += ' · okunuyor…';
        }
        ilerleme.textContent = metin;
    }

    function adim() {
        fetch(kutu.dataset.adres, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': jeton ? jeton.getAttribute('content') : '' },
        }).then(function (y) { return y.ok ? y.json() : Promise.reject(y.status); })
          .then(function (d) {
              yaz(d);
              if (d.waiting) { setTimeout(adim, 4000); return; }
              if (d.status === 'uploaded' || d.status === 'reading') {
                  if (d.background) { setTimeout(adim, 4000); } else { adim(); }
              } else { location.reload(); }
          })
          .catch(function () { location.reload(); });
    }

    if (form) { form.hidden = true; }
    if (ilerleme) { ilerleme.textContent = (ilerleme.textContent || '').trim() + ' · okunuyor…'; }
    adim();
})();
</script>
@endpush
