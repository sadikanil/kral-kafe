@extends('layouts.app')

@section('title', 'Masa Düzenle - Kral Kafe')
@section('page-title', 'Masa: ' . $table->name)

@section('page-actions')
    <a href="{{ route('admin.tables.qr', $table) }}" class="btn btn-secondary btn-sm">QR Kodu</a>
@endsection

@section('content')
    <div class="card" style="max-width: 520px;">
        <div class="card-body">
            <form action="{{ route('admin.tables.update', $table) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="name" class="form-label">Masa Adı *</label>
                    <input type="text" id="name" name="name" maxlength="50" required
                        class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name', $table->name) }}">
                    @error('name')
                        <span class="invalid-feedback">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label class="form-label">QR Kodu</label>
                    <input type="text" class="form-control" value="{{ $table->qr_code }}" readonly>
                    <small class="text-muted">
                        Değiştirilemez. Duvardaki basılı etiketler bu koda bakıyor.
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="is_active" value="1"
                            {{ old('is_active', $table->is_active) ? 'checked' : '' }}>
                        Masa kullanımda
                    </label>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                    <a href="{{ route('admin.tables.index') }}" class="btn btn-secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>
@endsection
