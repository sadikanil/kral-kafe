@extends('layouts.app')

@section('title', 'Kullanıcı Düzenle - Kral Kafe')
@section('page-title', 'Kullanıcı Düzenle')

@section('content')
    <div class="card" style="max-width: 600px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.users.update', $user) }}">
                @csrf
                @method('PUT')

                {{-- Bolumler (5 Ekim 2026): telefonda form uc ekran boyuydu. Kimlik
                     ve baglar acik; hedef/uyelik ve sifre katli, hatasi varsa acik. --}}
                <details class="form-section" open>
                    <summary>Kimlik</summary>

                <div class="form-group">
                    <label for="name" class="form-label">Ad Soyad *</label>
                    <input type="text" id="name" name="name" autocomplete="off" class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name', $user->name) }}" required>
                    @error('name')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Telefon</label>
                    <input type="tel" inputmode="tel" id="phone" name="phone" autocomplete="off" class="form-control @error('phone') is-invalid @enderror"
                        value="{{ old('phone', $user->phone ? \App\Support\Telefon::format($user->phone) : '') }}" placeholder="05XX XXX XX XX">
                    @error('phone')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">E-posta <span class="text-muted">(isteğe bağlı)</span></label>
                    <input type="email" id="email" name="email" autocomplete="off" class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email', $user->email) }}">
                    @error('email')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                </details>

                <details class="form-section" open>
                    <summary>Rol ve bağlar</summary>

                <div class="form-group">
                    <label for="role" class="form-label">Rol *</label>
                    {{-- Kendi hesabinda yalnizca yonetici secilebilir: baska rol hesabi
                         panelden kilitlerdi; sunucu da reddediyor (UserController::update).
                         Orada eski girdi yok sayilir: reddedilen istekten donen old('role')
                         kilitli bir secenegi secili cizerdi. Boylece selected yalnizca
                         yoneticiye, disabled yalnizca digerlerine duser, ikisi cakismaz. --}}
                    @php
                        $kendisi = $user->is(auth()->user());
                        $seciliRol = $kendisi ? \App\Enums\Role::Admin->value : old('role', $user->role);
                    @endphp
                    <select id="role" name="role" class="form-control @error('role') is-invalid @enderror" required
                        @if($kendisi) aria-describedby="role-kendi" @endif>
                        @foreach (\App\Enums\Role::cases() as $rol)
                            <option value="{{ $rol->value }}" {{ $seciliRol === $rol->value ? 'selected' : '' }}{{ $kendisi && $rol !== \App\Enums\Role::Admin ? 'disabled' : '' }}>{{ $rol->label() }}</option>
                        @endforeach
                    </select>
                    @if($kendisi)
                        <small class="text-muted" id="role-kendi">Kendi rolünü değiştiremezsin; başka bir yönetici değiştirebilir.</small>
                    @endif
                    @error('role')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                @include('admin.users._kocluk')

                @if($user->isStudent())
                    @include('admin.users._sinif-alan')
                @endif

                @if($user->hasRole(\App\Enums\Role::Parent))
                    <div class="form-group">
                        <label class="form-label">Velisi olduğu öğrenciler</label>
                        {{-- Gizli alan: hicbir kutu isaretli degilse de anahtar gitsin,
                             kontrolcu "hepsini kaldir" ile "bolum yoktu"yu ayirt edebilsin. --}}
                        <input type="hidden" name="student_ids" value="">
                        @if($linkableStudents->isEmpty())
                            <p class="text-muted mb-0">Sistemde kayıtlı öğrenci yok.</p>
                        @else
                            <div class="rounded p-2 js-aranabilir" style="max-height: 220px; overflow-y: auto; border: 1px solid var(--separator);">
                                @foreach($linkableStudents as $ogrenci)
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="student_{{ $ogrenci->id }}"
                                            name="student_ids[]" value="{{ $ogrenci->id }}"
                                            {{ in_array($ogrenci->id, old('student_ids', $linkedStudentIds) ?: []) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="student_{{ $ogrenci->id }}">
                                            {{ $ogrenci->name }} <span class="text-muted">({{ $ogrenci->contactLabel() }})</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('student_ids')
                            <span class="invalid-feedback d-block">{{ $message }}</span>
                        @enderror
                        @error('student_ids.*')
                            <span class="invalid-feedback d-block">{{ $message }}</span>
                        @enderror
                        <small class="text-muted">Yalnızca kendi çocukları. Veli bu öğrencilerin çalışma, ödeme ve paket bilgilerini görür.@if($user->isCoach()) Özel ders verdiği ya da koçluk yaptığı öğrencileri aşağıdaki listeden seçin.@endif</small>
                    </div>
                @endif

                {{--
                    Kocluk yaptigi ogrenciler (1 Ekim 2026). Veli bagindan AYRI:
                    veli cocugunun odemesini de gorur, koc gormez. Ikisi tek
                    listede durunca "ozel ders verdigi ogrenci" veli bagiyla
                    kuruluyordu ve koc sayfalarinda hic gorunmuyordu.
                --}}
                @if($user->isCoach() && ! $user->hasRole(\App\Enums\Role::Admin))
                    <div class="form-group">
                        <label class="form-label">Koçluk / özel ders verdiği öğrenciler</label>
                        <input type="hidden" name="coach_student_ids" value="">
                        @if($coachableStudents->isEmpty())
                            <p class="text-muted mb-0">Sistemde kayıtlı öğrenci yok.</p>
                        @else
                            <div class="rounded p-2 js-aranabilir" style="max-height: 220px; overflow-y: auto; border: 1px solid var(--separator);">
                                @foreach($coachableStudents as $ogrenci)
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="coach_student_{{ $ogrenci->id }}"
                                            name="coach_student_ids[]" value="{{ $ogrenci->id }}"
                                            @checked(in_array($ogrenci->id, old('coach_student_ids', $coachedStudentIds) ?: []))>
                                        <label class="form-check-label" for="coach_student_{{ $ogrenci->id }}">{{ $ogrenci->name }}</label>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('coach_student_ids.*')
                            <span class="invalid-feedback d-block">{{ $message }}</span>
                        @enderror
                        <small class="text-muted">Koç bu öğrencileri Çalışma Planları ve Özel Derslerim sayfalarında görür; plana yalnızca ödev ekler, ödeme ve paket bilgisi görmez.</small>
                    </div>
                @endif

                @if($user->hasRole(\App\Enums\Role::Student))
                    <div class="form-group">
                        <label class="form-label">Velileri</label>
                        <input type="hidden" name="parent_ids" value="">
                        @if($linkableParents->isEmpty())
                            <p class="text-muted mb-0">Sistemde kayıtlı veli yok.</p>
                        @else
                            <div class="rounded p-2 js-aranabilir" style="max-height: 220px; overflow-y: auto; border: 1px solid var(--separator);">
                                @foreach($linkableParents as $veli)
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="parent_{{ $veli->id }}"
                                            name="parent_ids[]" value="{{ $veli->id }}"
                                            {{ in_array($veli->id, old('parent_ids', $linkedParentIds) ?: []) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="parent_{{ $veli->id }}">
                                            {{ $veli->name }} <span class="text-muted">({{ $veli->contactLabel() }})</span>
                                            @if($veli->is_coach)<span class="badge badge-info">Koç{{ $veli->coach_subject ? ' · ' . $veli->coach_subject : '' }}</span>@endif
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('parent_ids')
                            <span class="invalid-feedback d-block">{{ $message }}</span>
                        @enderror
                        @error('parent_ids.*')
                            <span class="invalid-feedback d-block">{{ $message }}</span>
                        @enderror
                        {{-- 5 Ekim 2026: koc yetkili veli burada yalnizca KENDI cocugunu
                             isaretler; ozel ders/kocluk bagi asagidaki Koclar kartinda. --}}
                        @if($linkableParents->contains('is_coach', true))
                            <small class="text-muted">Koç etiketli veliler yalnızca kendi çocuklarıysa işaretlenir. Özel ders ya da koçluk verdiği öğrenci için aşağıdaki <strong>Koçlar</strong> kartını kullanın; veli bağı ödeme ve paket bilgisini de açar.</small>
                        @endif
                    </div>
                @endif

                </details>

                <details class="form-section" @if($errors->hasAny(['weekly_goal_hours', 'subscription_status'])) open @endif>
                    <summary>
                        Hedef ve üyelik
                        <span class="text-muted">· {{ ['active' => 'Aktif', 'inactive' => 'Pasif', 'suspended' => 'Askıda'][$user->subscription_status] ?? $user->subscription_status }}@if($weeklyGoal) · haftada {{ intdiv($weeklyGoal->target_minutes, 60) }} saat @endif</span>
                    </summary>
                <div class="form-group">
                    <label for="weekly_goal_hours" class="form-label">Haftalık Çalışma Hedefi (saat)</label>
                    <input type="number" id="weekly_goal_hours" name="weekly_goal_hours" min="1" max="120"
                        class="form-control @error('weekly_goal_hours') is-invalid @enderror"
                        value="{{ old('weekly_goal_hours', $weeklyGoal ? intdiv($weeklyGoal->target_minutes, 60) : '') }}"
                        placeholder="Örn. 20">
                    @error('weekly_goal_hours')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                    <small class="text-muted">
                        Değiştirince eski hedef kapatılır, yenisi bugünden başlar — geçmiş
                        haftaların sonucu olduğu gibi kalır. Boş bırakılırsa mevcut hedef korunur.
                    </small>
                </div>

                <div class="form-group">
                    <label for="subscription_status" class="form-label">Abonelik Durumu *</label>
                    <select id="subscription_status" name="subscription_status"
                        class="form-control @error('subscription_status') is-invalid @enderror" required>
                        <option value="active" {{ old('subscription_status', $user->subscription_status) == 'active' ? 'selected' : '' }}>Aktif</option>
                        <option value="inactive" {{ old('subscription_status', $user->subscription_status) == 'inactive' ? 'selected' : '' }}>Pasif</option>
                        <option value="suspended" {{ old('subscription_status', $user->subscription_status) == 'suspended' ? 'selected' : '' }}>Askıda</option>
                    </select>
                    @error('subscription_status')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                </details>

                <details class="form-section" @if($errors->hasAny(['password'])) open @endif>
                    <summary>Şifre değiştir</summary>
                <p class="text-muted mb-2">Şifreyi değiştirmek için doldurun (boş bırakırsanız değişmez)</p>

                <div class="form-group">
                    <label for="password" class="form-label">Yeni Şifre</label>
                    <input type="password" id="password" name="password" autocomplete="new-password"
                        class="form-control @error('password') is-invalid @enderror">
                    @error('password')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation" class="form-label">Şifre Tekrar</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" class="form-control">
                </div>
                </details>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Sifre sifirlama (Dalga 18b). Ana formun DISINDA: ic ice form gecersiz. --}}
    @if(! $user->is(auth()->user()))
        <div class="card mt-3" style="max-width: 600px;">
            <div class="card-body d-flex justify-content-between align-items-center gap-2">
                <div>
                    <strong>Şifre</strong>
                    <div class="text-muted" style="font-size:.85rem">
                        @if($user->password === null)
                            Henüz şifre yok — ilk girişte kendisi belirleyecek.
                        @else
                            Sıfırlarsan her cihazdan çıkarılır, sonraki girişte yeni şifre belirler.
                        @endif
                    </div>
                </div>
                @if($user->password !== null)
                    {{-- Ad @js ile: {{ }} kesme isaretini HTML'de kacirir ama tarayici onu
                         JS'ten once geri cevirir; "O'Neil" gibi bir ad onay kutusunu JS
                         hatasina dusurup formu ONAYSIZ gonderirdi. --}}
                    <form method="POST" action="{{ route('admin.users.reset-password', $user) }}"
                        onsubmit="return confirm(@js($user->name . ' her cihazdan çıkarılacak ve yeni şifre belirlemesi gerekecek. Emin misin?'))">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Şifreyi sıfırla</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @if($user->isStudent())
        @include('admin.users._paket')
    @endif

    @if($lessonSlots !== null)
        @include('admin.users._ozel-ders')
    @endif

    @if($user->isStudent())
        {{--
            Koc atamasi (Dalga 14). Ana formun DISINDA: ic ice form gecersiz
            HTML.

            Plan FORMU bu ekrandan /koc/plan altina tasindi. Burada kalan
            yalnizca "kim izliyor" sorusu; planin kendisi kendi sayfasinda,
            cunku ayni formu iki yerde tutmak birinin gunun birinde
            digerinden farkli davranmasi demekti.
        --}}
        <div class="card mt-3" style="max-width: 640px;">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4>Koçlar</h4>
                <a href="{{ route('coach.plan.show', $user) }}" class="btn btn-sm btn-secondary">
                    Çalışma planı →
                </a>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    Koç, atandığı öğrencinin çalışma planını hazırlar ve verilerini görür.
                    Bir öğrencinin birden fazla koçu olabilir; yönetici zaten tüm
                    öğrencileri görür, atanması gerekmez.
                </p>

                <form method="POST" action="{{ route('admin.coaches.attach', $user) }}"
                      class="d-flex align-items-center gap-2 mb-3">
                    @csrf
                    <select name="coach_id" class="form-control" aria-label="Atanacak koç" required>
                        <option value="">Koç seç</option>
                        @foreach($assignableCoaches as $aday)
                            <option value="{{ $aday->id }}">
                                {{ $aday->coachLabel() }} ({{ $aday->displayRole()?->label() }})
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary">Ata</button>
                </form>

                @forelse($assignedCoaches as $atanan)
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <div>
                            <strong>{{ $atanan->name }}</strong>
                            {{-- Brans ve asil rol (5 Ekim 2026): "Koç · Matematik · Veli". --}}
                            <div class="text-muted">
                                {{ collect([$atanan->displayRole()?->label(), $atanan->coach_subject,
                                    $atanan->displayRole() !== $atanan->role() ? $atanan->role()?->label() : null])->filter()->implode(' · ') }}
                            </div>
                        </div>

                        <form method="POST" action="{{ route('admin.coaches.detach', [$user, $atanan]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger">Kaldır</button>
                        </form>
                    </div>
                @empty
                    <p class="text-muted">Bu öğrenciye atanmış koç yok.</p>
                @endforelse
            </div>
        </div>
    @endif

@endsection

@push('scripts')
<script>
    // Uzun ogrenci/veli listelerinde arama (5 Ekim 2026). Betik yoksa liste
    // oldugu gibi kalir; 8'den kisa listeye kutu eklenmez.
    (function () {
        document.querySelectorAll('.js-aranabilir').forEach(function (liste) {
            const satirlar = liste.querySelectorAll('.form-check');
            if (satirlar.length < 8) { return; }

            const kutu = document.createElement('input');
            kutu.type = 'search';
            kutu.className = 'form-control mb-2';
            kutu.placeholder = 'Ara…';
            kutu.setAttribute('aria-label', 'Listede ara');
            liste.before(kutu);

            kutu.addEventListener('input', function () {
                const aranan = kutu.value.trim().toLocaleLowerCase('tr');
                satirlar.forEach(function (satir) {
                    satir.hidden = aranan !== '' && !satir.textContent.toLocaleLowerCase('tr').includes(aranan);
                });
            });
        });
    })();
</script>
@endpush