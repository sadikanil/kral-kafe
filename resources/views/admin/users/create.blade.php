@extends('layouts.app')

@section('title', 'Yeni Kullanıcı - Kral Kafe')
@section('page-title', 'Yeni Kullanıcı Ekle')

{{--
    Dalga 20: tek sayfa, alanlar sirayla acilir.
      Rol ogrenci    -> Paket + Veli bolumu
      Paket koclukluysa (ana ya da ek) -> Koc
    Gizleme yalnizca kolaylik; kurallari sunucu uyguluyor.
--}}
@section('content')
    <div class="card" style="max-width: 640px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.users.store') }}" id="kullaniciFormu">
                @csrf

                {{-- 1 · Kisi --}}
                <div class="form-group">
                    <label for="name" class="form-label">Ad Soyad *</label>
                    <input type="text" id="name" name="name" autocomplete="off" class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name') }}" required autofocus>
                    @error('name')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Telefon *</label>
                    <input type="tel" inputmode="tel" id="phone" name="phone" autocomplete="off" class="form-control @error('phone') is-invalid @enderror"
                        value="{{ old('phone') }}" placeholder="05XX XXX XX XX" required>
                    <span class="text-muted" style="font-size:.85rem">Kullanıcı bu numarayla giriş yapar; şifresini ilk girişte kendisi belirler.</span>
                    @error('phone')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">E-posta <span class="text-muted">(isteğe bağlı)</span></label>
                    <input type="email" id="email" name="email" autocomplete="off" class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email') }}">
                    @error('email')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="role" class="form-label">Rol *</label>
                    <select id="role" name="role" class="form-control @error('role') is-invalid @enderror" required>
                        @foreach (\App\Enums\Role::cases() as $rol)
                            <option value="{{ $rol->value }}" {{ old('role', \App\Enums\Role::Student->value) === $rol->value ? 'selected' : '' }}>{{ $rol->label() }}</option>
                        @endforeach
                    </select>
                    @error('role')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>

                <div id="ogrenciBolumu">
                    <hr>
                    @include('admin.users._sinif-alan', ['user' => null])

                    {{-- 2 · Paket --}}
                    <div class="form-group">
                        <label for="package_id" class="form-label">Paket *</label>
                        <select id="package_id" name="package_id" class="form-control @error('package_id') is-invalid @enderror">
                            <option value="">Seçin…</option>
                            @foreach($packages as $paket)
                                <option value="{{ $paket->id }}" data-kocluk="{{ $paket->includes_coaching ? 1 : 0 }}"
                                    {{ (string) old('package_id') === (string) $paket->id ? 'selected' : '' }}>
                                    {{ $paket->tier ? 'Tier ' . $paket->tier . ' · ' : '' }}{{ $paket->name }} — {{ $paket->formattedPrice() }}
                                </option>
                            @endforeach
                        </select>
                        @error('package_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>

                    @if($addons->isNotEmpty())
                        <div class="form-group">
                            <label class="form-label">Ek paketler</label>
                            @foreach($addons as $ek)
                                <label class="form-label d-flex align-items-center gap-2">
                                    <input type="checkbox" name="addon_ids[]" value="{{ $ek->id }}" data-kocluk="{{ $ek->includes_coaching ? 1 : 0 }}"
                                        {{ in_array($ek->id, old('addon_ids', [])) ? 'checked' : '' }}>
                                    {{ $ek->name }} — {{ $ek->formattedPrice() }}
                                </label>
                            @endforeach
                        </div>
                    @endif

                    {{-- 3 · Koc (paket koclukluysa) --}}
                    <div class="form-group" id="kocBolumu">
                        <label for="coach_id" class="form-label">Koç *</label>
                        <select id="coach_id" name="coach_id" class="form-control @error('coach_id') is-invalid @enderror">
                            <option value="">Seçin…</option>
                            @foreach($coaches as $koc)
                                <option value="{{ $koc->id }}" {{ (string) old('coach_id') === (string) $koc->id ? 'selected' : '' }}>
                                    {{ $koc->name }}{{ $koc->isAdmin() ? ' (yönetici)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('coach_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>

                    {{-- 4 · Veli (en az bir) --}}
                    <hr>
                    <div class="form-group">
                        <label class="form-label">Veli * <span class="text-muted">(en az bir)</span></label>
                        @error('parent_ids')<div class="alert alert-danger mb-2">{{ $message }}</div>@enderror
                        @error('parent_ids.*')<div class="alert alert-danger mb-2">{{ $message }}</div>@enderror

                        @if($parents->isNotEmpty())
                            <input type="search" id="veliAra" class="form-control mb-2" aria-label="Kayıtlı veli ara"
                                enterkeyhint="search" autocomplete="off" placeholder="Kayıtlı veli ara…">
                            <div class="rounded p-2 mb-2" style="max-height: 200px; overflow-y: auto; border: 1px solid var(--gray-200);">
                                @foreach($parents as $veli)
                                    {{-- Arama metni JS'teki toLocaleLowerCase('tr') ile ayni kuralla
                                         kucultulur: mb_strtolower Turkce degil ('İ' -> i + birlesik
                                         nokta, 'I' -> i), "İsmail" ya da "Işık" yazan hic bulamazdi.
                                         Telefon ekranda gorundugu bicimiyle de aranir ("0532 111"). --}}
                                    <label class="d-flex align-items-center gap-2 mb-1 js-veli"
                                        data-ara="{{ mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $veli->name . ' ' . $veli->contactLabel() . ' ' . $veli->phone)) }}">
                                        <input type="checkbox" name="parent_ids[]" value="{{ $veli->id }}"
                                            {{ in_array($veli->id, old('parent_ids', [])) ? 'checked' : '' }}>
                                        {{ $veli->name }} <span class="text-muted">({{ $veli->contactLabel() }})</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="text-muted mb-2" style="font-size:.85rem">Listede yoksa aşağıya yeni veli yaz:</div>
                        @endif

                        {{-- Gorunur etiket: placeholder yazmaya baslayinca kayboluyordu. --}}
                        <div class="d-flex gap-2" style="flex-wrap: wrap;">
                            <div style="flex: 1 1 200px;">
                                <label for="new_parent_name" class="form-label">Yeni veli adı soyadı</label>
                                <input type="text" id="new_parent_name" name="new_parent_name" autocomplete="off"
                                    class="form-control @error('new_parent_name') is-invalid @enderror" value="{{ old('new_parent_name') }}">
                            </div>
                            <div style="flex: 1 1 160px;">
                                <label for="new_parent_phone" class="form-label">Yeni velinin telefonu</label>
                                <input type="tel" inputmode="tel" id="new_parent_phone" name="new_parent_phone" autocomplete="off"
                                    class="form-control @error('new_parent_phone') is-invalid @enderror"
                                    value="{{ old('new_parent_phone') }}" placeholder="05XX XXX XX XX">
                            </div>
                        </div>
                        @error('new_parent_phone')<span class="invalid-feedback" style="display:block">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Kullanıcı Ekle</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        const form = document.getElementById('kullaniciFormu');
        const rol = document.getElementById('role');
        const ogrenci = document.getElementById('ogrenciBolumu');
        const koc = document.getElementById('kocBolumu');
        const paket = document.getElementById('package_id');

        function kocluk() {
            const secili = paket.options[paket.selectedIndex];
            const ana = secili && secili.dataset.kocluk === '1';
            const ek = [...form.querySelectorAll('input[name="addon_ids[]"]:checked')].some(k => k.dataset.kocluk === '1');
            return ana || ek;
        }

        function guncelle() {
            ogrenci.style.display = rol.value === 'student' ? '' : 'none';
            koc.style.display = kocluk() ? '' : 'none';
        }

        form.addEventListener('change', guncelle);
        guncelle();

        const ara = document.getElementById('veliAra');
        if (ara) {
            ara.addEventListener('input', function () {
                const q = ara.value.toLocaleLowerCase('tr');
                form.querySelectorAll('.js-veli').forEach(function (s) {
                    s.style.display = s.dataset.ara.includes(q) ? '' : 'none';
                });
            });
        }
    })();
</script>
@endpush
