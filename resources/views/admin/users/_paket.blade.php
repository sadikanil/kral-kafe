{{--
    Paket: simdiki paket, yenileme durumu ve "paketi degistir".
    18 Kas 2026: paket her ay kendiliginden yenilenir; degisiklik ya sonraki
    donemden ya da bu donemden (yerinde) gecerli olur, yeni tam fiyatli donem
    acmaz. Beklenen: $user, $currentSubscription, $switchPackages.
--}}
<div class="card mt-3" style="max-width: 640px;">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4>Paket</h4>
        <a href="{{ route('admin.subscriptions.index', $user) }}" class="btn btn-sm btn-secondary">Paket geçmişi ve ödemeler →</a>
    </div>
    <div class="card-body">
        @if($currentSubscription)
            <p class="mb-1">
                <strong>{{ $currentSubscription->package->name }}</strong>
                <span class="text-muted">
                    · {{ $currentSubscription->starts_on->format('d.m.Y') }} – {{ $currentSubscription->ends_on->format('d.m.Y') }}
                    · {{ $currentSubscription->formattedPrice() }}
                </span>
            </p>
            @include('admin.subscriptions._yenileme', ['abonelik' => $currentSubscription])
        @else
            <p class="text-muted mb-3">Şu an yürürlükte bir paketi yok.</p>
        @endif

        <form method="POST" action="{{ route('admin.subscriptions.switch', $user) }}" class="mt-3">
            @csrf
            <p class="mb-2"><strong>Paketi değiştir</strong></p>
            <div class="d-flex gap-2" style="flex-wrap: wrap;">
                <div class="form-group" style="flex: 2; min-width: 180px;">
                    <label for="switch_package" class="form-label">Yeni paket</label>
                    <select id="switch_package" name="package_id" class="form-control @error('package_id') is-invalid @enderror" required>
                        @foreach($switchPackages as $paket)
                            <option value="{{ $paket->id }}" @selected((int) old('package_id', $currentSubscription?->package_id) === $paket->id)>
                                {{ $paket->name }} · {{ number_format((float) $paket->monthly_price, 0, ',', '.') }} ₺
                            </option>
                        @endforeach
                    </select>
                    @error('package_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group" style="flex: 1; min-width: 120px;">
                    <label for="switch_price" class="form-label">Aylık tutar (₺)</label>
                    <input type="number" step="0.01" min="0" id="switch_price" name="price" class="form-control"
                           value="{{ old('price') }}" placeholder="paket fiyatı">
                </div>
            </div>

            @if($currentSubscription)
                <fieldset class="form-group mb-2" style="border: 0; padding: 0; margin-left: 0; margin-right: 0;">
                    <legend class="form-label">Ne zaman?</legend>
                    <label class="d-block">
                        <input type="radio" name="when" value="sonraki" @checked(old('when', 'sonraki') === 'sonraki')>
                        Sonraki dönemden ({{ $currentSubscription->renewsOn()->format('d.m.Y') }})
                        <span class="text-muted">· bu dönem aynen kalır</span>
                    </label>
                    <label class="d-block">
                        <input type="radio" name="when" value="simdi" @checked(old('when') === 'simdi')>
                        Bu dönemden
                        <span class="text-muted">· bu dönemin paketi ve tutarı değişir; ödenen korunur</span>
                    </label>
                    @error('when')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                </fieldset>
            @else
                <input type="hidden" name="when" value="simdi">
                <p class="form-text mb-2">Bugünden itibaren bir aylık yeni dönem açılır ve her ay yenilenir.</p>
            @endif

            <button type="submit" class="btn btn-primary" data-confirm="Paket değişsin mi?">Paketi değiştir</button>
        </form>
    </div>
</div>
