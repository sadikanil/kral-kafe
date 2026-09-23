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
            <select name="weekday" class="form-control" style="flex: 1 1 130px;">
                @foreach(\App\Models\PrivateLessonSlot::GUNLER as $no => $ad)
                    <option value="{{ $no }}">{{ $ad }}</option>
                @endforeach
            </select>
            <input type="time" name="starts_at" class="form-control" style="flex: 1 1 100px;" required>
            <input type="time" name="ends_at" class="form-control" style="flex: 1 1 100px;" required>
            <button type="submit" class="btn btn-primary">Ekle</button>
        </form>

        @if($upcomingLessons !== [])
            <hr>
            <strong>Önümüzdeki 4 hafta</strong>
            @foreach($upcomingLessons as $ders)
                <div class="d-flex justify-content-between align-items-center mt-2" style="flex-wrap: wrap; gap: .5rem;">
                    <span>
                        {{ \Illuminate\Support\Carbon::parse($ders['date'])->locale('tr')->translatedFormat('d M D') }}
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
                                <input type="date" name="new_date" class="form-control" style="padding: 4px; width: 140px;" value="{{ $ders['date'] }}" required>
                                <input type="time" name="new_starts_at" class="form-control" style="padding: 4px; width: 90px;" value="{{ $ders['starts_at'] }}" required>
                                <input type="time" name="new_ends_at" class="form-control" style="padding: 4px; width: 90px;" value="{{ $ders['ends_at'] }}" required>
                                <button type="submit" class="btn btn-sm btn-secondary">Taşı</button>
                            </form>
                        </span>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
