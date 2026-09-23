{{--
    Ozel ders (Dalga 25). Haftalik saat + onumuzdeki 4 hafta; tek dersi
    iptal et ya da tasi. Yalnizca yonetici gorur/duzenler.
    Beklenen: $user, $lessonSlots, $upcomingLessons
--}}
<div class="card mt-3" style="max-width: 640px;">
    <div class="card-header"><h4>👨‍🏫 Özel ders</h4></div>
    <div class="card-body">
        @forelse($lessonSlots as $saat)
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span>Her {{ $saat->label() }}</span>
                <form method="POST" action="{{ route('admin.lessons.destroy', $saat) }}" onsubmit="return confirm('Bu haftalık saat kaldırılsın mı?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-secondary">Kaldır</button>
                </form>
            </div>
        @empty
            <p class="text-muted">Henüz haftalık ders saati yok.</p>
        @endforelse

        <form method="POST" action="{{ route('admin.lessons.store', $user) }}" class="d-flex gap-2 mt-2" style="flex-wrap: wrap; align-items: flex-end;">
            @csrf
            {{-- Gorunur etiketler: iki bos saat kutusundan hangisinin baslangic
                 oldugu yalnizca sirasindan anlasiliyordu. --}}
            <div style="flex: 1 1 130px;">
                <label for="ders-gun" class="form-label">Gün</label>
                <select id="ders-gun" name="weekday" class="form-control">
                    @foreach(\App\Models\PrivateLessonSlot::GUNLER as $no => $ad)
                        <option value="{{ $no }}">{{ $ad }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex: 1 1 100px;">
                <label for="ders-bas" class="form-label">Başlangıç</label>
                <input type="time" id="ders-bas" name="starts_at" class="form-control" required>
            </div>
            <div style="flex: 1 1 100px;">
                <label for="ders-bit" class="form-label">Bitiş</label>
                <input type="time" id="ders-bit" name="ends_at" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Ekle</button>
        </form>

        @if($upcomingLessons !== [])
            <hr>
            <strong>Önümüzdeki 4 hafta</strong>
            @foreach($upcomingLessons as $ders)
                @php $gunAdi = \Illuminate\Support\Carbon::parse($ders['date'])->locale('tr')->translatedFormat('d M D'); @endphp
                <div class="d-flex justify-content-between align-items-center mt-2" style="flex-wrap: wrap; gap: .5rem;">
                    <span>
                        {{ $gunAdi }}
                        {{ $ders['starts_at'] }}–{{ $ders['ends_at'] }}
                        @if($ders['status'] === 'cancelled')<span class="badge badge-danger">İptal</span>@endif
                        @if($ders['status'] === 'moved')<span class="badge badge-warning">Taşındı</span>@endif
                    </span>
                    @if($ders['status'] !== 'cancelled')
                        <span class="d-flex gap-1" style="flex-wrap: wrap;">
                            <form method="POST" action="{{ route('admin.lessons.cancel', $ders['slot']) }}">
                                @csrf <input type="hidden" name="date" value="{{ $ders['original_date'] }}">
                                <button type="submit" class="btn btn-sm btn-secondary">İptal et</button>
                            </form>
                            <form method="POST" action="{{ route('admin.lessons.move', $ders['slot']) }}" class="d-flex gap-1">
                                @csrf <input type="hidden" name="date" value="{{ $ders['original_date'] }}">
                                {{-- Satir ici form: gorunur etiket yer yok, ad satirin dersini soyler. --}}
                                <input type="date" name="new_date" class="form-control" style="padding: 4px; width: 140px;" value="{{ $ders['date'] }}" required
                                    aria-label="{{ $gunAdi }} dersinin yeni günü">
                                <input type="time" name="new_starts_at" class="form-control" style="padding: 4px; width: 90px;" value="{{ $ders['starts_at'] }}" required
                                    aria-label="{{ $gunAdi }} dersinin yeni başlangıcı">
                                <input type="time" name="new_ends_at" class="form-control" style="padding: 4px; width: 90px;" value="{{ $ders['ends_at'] }}" required
                                    aria-label="{{ $gunAdi }} dersinin yeni bitişi">
                                <button type="submit" class="btn btn-sm btn-secondary">Taşı</button>
                            </form>
                        </span>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
