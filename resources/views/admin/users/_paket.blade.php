{{--
    Paket (Dalga 30a): simdiki paket + "tarihten itibaren degistir".
    Eski paket bir gun once biter, yenisi o gun baslar; gecmis ve odemeler
    odeme ekraninda aynen kalir. Beklenen: $user, $currentSubscription,
    $switchPackages.
--}}
<div class="card mt-3" style="max-width: 640px;">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4>Paket</h4>
        <a href="{{ route('admin.subscriptions.index', $user) }}" class="btn btn-sm btn-secondary">Paket geçmişi ve ödemeler →</a>
    </div>
    <div class="card-body">
        @if($currentSubscription)
            <p class="mb-3">
                <strong>{{ $currentSubscription->package->name }}</strong>
                <span class="text-muted">
                    · {{ $currentSubscription->starts_on->format('d.m.Y') }} – {{ $currentSubscription->ends_on->format('d.m.Y') }}
                    · {{ $currentSubscription->formattedPrice() }}
                </span>
            </p>
        @else
            <p class="text-muted mb-3">Şu an yürürlükte bir paketi yok.</p>
        @endif

        <form method="POST" action="{{ route('admin.subscriptions.switch', $user) }}">
            @csrf
            <p class="mb-2"><strong>Paketi değiştir</strong></p>
            <div class="d-flex gap-2" style="flex-wrap: wrap;">
                <div class="form-group" style="flex: 2; min-width: 180px;">
                    <label for="switch_package" class="form-label">Yeni paket</label>
                    <select id="switch_package" name="package_id" class="form-control @error('package_id') is-invalid @enderror" required>
                        @foreach($switchPackages as $paket)
                            <option value="{{ $paket->id }}" @selected((int) old('package_id') === $paket->id)>
                                {{ $paket->name }} · {{ number_format((float) $paket->monthly_price, 0, ',', '.') }} ₺
                            </option>
                        @endforeach
                    </select>
                    @error('package_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group" style="flex: 1; min-width: 150px;">
                    <label for="switch_on" class="form-label">Geçiş günü</label>
                    <input type="date" id="switch_on" name="switch_on" class="form-control @error('switch_on') is-invalid @enderror"
                           value="{{ old('switch_on', \App\Support\LocalDay::today()) }}" required>
                    @error('switch_on')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group" style="flex: 1; min-width: 120px;">
                    <label for="switch_price" class="form-label">Fiyat (₺)</label>
                    <input type="number" step="0.01" min="0" id="switch_price" name="price" class="form-control"
                           value="{{ old('price') }}" placeholder="paket fiyatı">
                </div>
            </div>
            <p class="form-text mb-2">Eski paket geçiş gününden bir gün önce biter; yeni paket o gün başlar ve eskinin bitiş gününe kadar sürer.</p>
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Paket seçilen günden itibaren değişecek. Emin misin?')">Paketi değiştir</button>
        </form>
    </div>
</div>
