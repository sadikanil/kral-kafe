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
                    <label for="email" class="form-label">E-posta *</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email') }}" required>
                    @error('email')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Telefon</label>
                    <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror"
                        value="{{ old('phone') }}" placeholder="05XX XXX XX XX">
                    @error('phone')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="role" class="form-label">Rol *</label>
                    <select id="role" name="role" class="form-control @error('role') is-invalid @enderror" required>
                        <option value="student" {{ old('role') == 'student' ? 'selected' : '' }}>Öğrenci</option>
                        <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Yönetici</option>
                    </select>
                    @error('role')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Şifre *</label>
                    <input type="password" id="password" name="password"
                        class="form-control @error('password') is-invalid @enderror" required>
                    @error('password')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation" class="form-label">Şifre Tekrar *</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                        required>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Kullanıcı Ekle</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>
@endsection