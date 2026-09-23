@extends('layouts.admin')

@section('title', 'Yeni Kullanıcı - Kral Kafe')
@section('page-title', 'Yeni Kullanıcı Ekle')

@section('content')
    <div class="card" style="max-width: 600px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.users.store') }}">
                @csrf

                <div class="form-group">
                    <label for="name" class="form-label">Ad Soyad *</label>
                    <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name') }}" required>
                    @error('name')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Telefon *</label>
                    <input type="tel" inputmode="tel" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror"
                        value="{{ old('phone') }}" placeholder="05XX XXX XX XX" required>
                    <span class="text-muted" style="font-size:.85rem">Kullanıcı bu numarayla giriş yapar.</span>
                    @error('phone')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">E-posta <span class="text-muted">(isteğe bağlı)</span></label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email') }}">
                    @error('email')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="role" class="form-label">Rol *</label>
                    <select id="role" name="role" class="form-control @error('role') is-invalid @enderror" required>
                        @foreach (\App\Enums\Role::cases() as $rol)
                            <option value="{{ $rol->value }}" {{ old('role', \App\Enums\Role::Student->value) === $rol->value ? 'selected' : '' }}>{{ $rol->label() }}</option>
                        @endforeach
                    </select>
                    @error('role')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <p class="text-muted">Şifre gerekmez: kullanıcı ilk girişinde telefon numarasını yazar ve şifresini kendisi belirler.</p>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Kullanıcı Ekle</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>
@endsection