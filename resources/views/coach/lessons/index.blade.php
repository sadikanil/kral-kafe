@extends('layouts.app')

@section('title', 'Özel Derslerim - Kral Kafe')
@section('page-title', 'Özel Derslerim')

{{--
    Kocun verdigi ozel dersler (1 Ekim 2026). Haftalik saatler + onumuzdeki
    4 hafta; tek dersi iptal et/tasi, haftalik saati degistir.

    Ucret, paket ve odeme BU EKRANDA YOK (karar): koclarin ozel dersten
    kazandigina karisilmiyor. Ders ekleme ve silme yoneticide.
--}}
@section('content')
    <p class="text-muted mb-3">
        Verdiğin özel dersler. Tek dersi iptal edebilir ya da taşıyabilir, haftalık saati değiştirebilirsin.
        Yeni öğrenci ya da ders eklemek için yöneticiye yaz.
    </p>

    @if($slots->isEmpty())
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon"><x-icon name="graduation-cap" /></div>
                    <div class="empty-state-title">Henüz özel dersin yok</div>
                    <p class="text-muted">Özel ders saatlerini yönetici (Cahit Hoca) tanımlar.</p>
                </div>
            </div>
        </div>
    @else
        <div class="card mb-3">
            <div class="card-header"><h4>Haftalık saatler ({{ $slots->count() }})</h4></div>
            <div class="card-body p-0">
                @foreach($slots as $saat)
                    <div class="list-row">
                        <span class="list-row-main">
                            <span class="list-row-title">{{ $saat->student->name }}</span>
                            <span class="list-row-sub">Her {{ $saat->label() }}{{ $saat->branch ? ' · ' . $saat->branch : '' }}</span>
                        <details class="mt-1">
                            <summary class="btn btn-sm btn-secondary" aria-label="{{ $saat->student->name }} dersinin saatini değiştir">Saati değiştir</summary>
                            <form method="POST" action="{{ route('coach.lessons.reschedule', $saat) }}" class="mt-1" style="max-width: 320px;">
                                @csrf @method('PATCH')
                                <label for="gun-{{ $saat->id }}" class="form-label">Gün</label>
                                <select id="gun-{{ $saat->id }}" name="weekday" class="form-control">
                                    @foreach(\App\Models\PrivateLessonSlot::GUNLER as $no => $ad)
                                        <option value="{{ $no }}" @selected($saat->weekday === $no)>{{ $ad }}</option>
                                    @endforeach
                                </select>
                                <div class="d-flex gap-1 mt-1">
                                    <input type="time" name="starts_at" class="form-control" value="{{ $saat->starts_at }}" required aria-label="Başlangıç">
                                    <input type="time" name="ends_at" class="form-control" value="{{ $saat->ends_at }}" required aria-label="Bitiş">
                                </div>
                                <small class="text-muted d-block mt-1">Bugünden itibaren geçerli; geçmiş dersler eski saatte kalır.</small>
                                <button type="submit" class="btn btn-sm btn-primary mt-1">Kaydet</button>
                            </form>
                        </details>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h4>Önümüzdeki 4 hafta</h4></div>
            <div class="card-body">
                @forelse($upcoming as $ders)
                    @php $gunAdi = \Illuminate\Support\Carbon::parse($ders['date'])->locale('tr')->translatedFormat('d M D'); @endphp
                    <div class="d-flex justify-content-between align-items-center mb-2" style="flex-wrap: wrap; gap: .5rem;">
                        <span>
                            <strong>{{ $gunAdi }}</strong>
                            {{ $ders['starts_at'] }}–{{ $ders['ends_at'] }}
                            · {{ $ders['slot']->student->name }}
                            @if($ders['status'] === 'cancelled')<span class="badge badge-danger">İptal</span>@endif
                            @if($ders['status'] === 'moved')<span class="badge badge-warning">Taşındı</span>@endif
                        </span>
                        @if($ders['status'] !== 'cancelled')
                            {{-- Tasima formu katli: telefonda her derste uc kutu
                                 acik dururken liste dort haftada ekrani dolduruyordu. --}}
                            <span class="d-flex gap-1 align-items-center" style="flex-wrap: wrap;">
                                <form method="POST" action="{{ route('coach.lessons.cancel', $ders['slot']) }}"
                                      onsubmit="return confirm('Bu ders iptal edilsin mi?')">
                                    @csrf <input type="hidden" name="date" value="{{ $ders['original_date'] }}">
                                    <button type="submit" class="btn btn-sm btn-secondary">İptal et</button>
                                </form>
                                <details>
                                    <summary class="btn btn-sm btn-secondary">Taşı</summary>
                                    <form method="POST" action="{{ route('coach.lessons.move', $ders['slot']) }}" class="mt-1" style="max-width: 320px;">
                                        @csrf <input type="hidden" name="date" value="{{ $ders['original_date'] }}">
                                        <input type="date" name="new_date" class="form-control" value="{{ $ders['date'] }}" required
                                            aria-label="{{ $gunAdi }} dersinin yeni günü">
                                        <div class="d-flex gap-1 mt-1">
                                            <input type="time" name="new_starts_at" class="form-control" value="{{ $ders['starts_at'] }}" required
                                                aria-label="{{ $gunAdi }} dersinin yeni başlangıcı">
                                            <input type="time" name="new_ends_at" class="form-control" value="{{ $ders['ends_at'] }}" required
                                                aria-label="{{ $gunAdi }} dersinin yeni bitişi">
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary mt-1">Kaydet</button>
                                    </form>
                                </details>
                            </span>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">Önümüzdeki dört haftada ders yok.</p>
                @endforelse
            </div>
        </div>
    @endif
@endsection
