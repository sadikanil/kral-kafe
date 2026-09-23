@extends('layouts.app')

@section('title', 'Kullanıcı Düzenle - Kral Kafe')
@section('page-title', 'Kullanıcı Düzenle')

@section('content')
    <div class="card" style="max-width: 600px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.users.update', $user) }}">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="name" class="form-label">Ad Soyad *</label>
                    <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name', $user->name) }}" required>
                    @error('name')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Telefon</label>
                    <input type="tel" inputmode="tel" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror"
                        value="{{ old('phone', $user->phone ? \App\Support\Telefon::format($user->phone) : '') }}" placeholder="05XX XXX XX XX">
                    @error('phone')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">E-posta <span class="text-muted">(isteğe bağlı)</span></label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email', $user->email) }}">
                    @error('email')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="role" class="form-label">Rol *</label>
                    <select id="role" name="role" class="form-control @error('role') is-invalid @enderror" required>
                        @foreach (\App\Enums\Role::cases() as $rol)
                            <option value="{{ $rol->value }}" {{ old('role', $user->role) === $rol->value ? 'selected' : '' }}>{{ $rol->label() }}</option>
                        @endforeach
                    </select>
                    @error('role')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

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

                @if($user->hasRole(\App\Enums\Role::Parent))
                    <div class="form-group">
                        <label class="form-label">Bağlı Öğrenciler</label>
                        {{-- Gizli alan: hicbir kutu isaretli degilse de anahtar gitsin,
                             kontrolcu "hepsini kaldir" ile "bolum yoktu"yu ayirt edebilsin. --}}
                        <input type="hidden" name="student_ids" value="">
                        @if($linkableStudents->isEmpty())
                            <p class="text-muted mb-0">Sistemde kayıtlı öğrenci yok.</p>
                        @else
                            <div class="rounded p-2" style="max-height: 220px; overflow-y: auto; border: 1px solid var(--gray-200, #e5e7eb);">
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
                        <small class="text-muted">Veli, yalnızca burada işaretli öğrencilerin çalışma bilgilerini görür.</small>
                    </div>
                @endif

                @if($user->hasRole(\App\Enums\Role::Student))
                    <div class="form-group">
                        <label class="form-label">Velileri</label>
                        <input type="hidden" name="parent_ids" value="">
                        @if($linkableParents->isEmpty())
                            <p class="text-muted mb-0">Sistemde kayıtlı veli yok.</p>
                        @else
                            <div class="rounded p-2" style="max-height: 220px; overflow-y: auto; border: 1px solid var(--gray-200, #e5e7eb);">
                                @foreach($linkableParents as $veli)
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="parent_{{ $veli->id }}"
                                            name="parent_ids[]" value="{{ $veli->id }}"
                                            {{ in_array($veli->id, old('parent_ids', $linkedParentIds) ?: []) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="parent_{{ $veli->id }}">
                                            {{ $veli->name }} <span class="text-muted">({{ $veli->contactLabel() }})</span>
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
                    </div>
                @endif

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

                <hr>

                <p class="text-muted mb-2">Şifreyi değiştirmek için doldurun (boş bırakırsanız değişmez)</p>

                <div class="form-group">
                    <label for="password" class="form-label">Yeni Şifre</label>
                    <input type="password" id="password" name="password"
                        class="form-control @error('password') is-invalid @enderror">
                    @error('password')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation" class="form-label">Şifre Tekrar</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control">
                </div>

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
                    <form method="POST" action="{{ route('admin.users.reset-password', $user) }}"
                        onsubmit="return confirm('{{ $user->name }} her cihazdan çıkarılacak ve yeni şifre belirlemesi gerekecek. Emin misin?')">
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
                    <select name="coach_id" class="form-control" required>
                        <option value="">Koç seç</option>
                        @foreach($assignableCoaches as $aday)
                            <option value="{{ $aday->id }}">
                                {{ $aday->name }} ({{ $aday->role()?->label() }})
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary">Ata</button>
                </form>

                @forelse($assignedCoaches as $atanan)
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <div>
                            <strong>{{ $atanan->name }}</strong>
                            <div class="text-muted">{{ $atanan->role()?->label() }}</div>
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