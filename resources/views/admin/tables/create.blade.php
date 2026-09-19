@extends('layouts.admin')

@section('title', 'Yeni Masa - Kral Kafe')
@section('page-title', 'Yeni Masa')

@section('content')
    <div class="card" style="max-width: 520px;">
        <div class="card-body">
            <form action="{{ route('admin.tables.store') }}" method="POST">
                @csrf

                <div class="form-group">
                    <label for="name" class="form-label">Masa Adı *</label>
                    <input type="text" id="name" name="name" maxlength="50" required autofocus
                        class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name') }}" placeholder="Masa 1">
                    @error('name')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                    <small class="text-muted">
                        QR kodu kaydedince kendiliğinden üretilir ve bir daha değişmez —
                        basılan etiketler kalıcı olarak geçerli kalır.
                    </small>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                    <a href="{{ route('admin.tables.index') }}" class="btn btn-secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
@endsection
