@extends('layouts.admin')

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
                    <label for="email" class="form-label">E-posta *</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email', $user->email) }}" required>
                    @error('email')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Telefon</label>
                    <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror"
                        value="{{ old('phone', $user->phone) }}">
                    @error('phone')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="role" class="form-label">Rol *</label>
                    <select id="role" name="role" class="form-control @error('role') is-invalid @enderror" required>
                        <option value="student" {{ old('role', $user->role) == 'student' ? 'selected' : '' }}>Öğrenci</option>
                        <option value="admin" {{ old('role', $user->role) == 'admin' ? 'selected' : '' }}>Yönetici</option>
                    </select>
                    @error('role')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
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
@endsection