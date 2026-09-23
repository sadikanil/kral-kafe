@extends('layouts.app')

@section('title', 'Masalar - Kral Kafe')
@section('page-title', 'Masalar')

@section('page-actions')
    <a href="{{ route('admin.tables.print-qr') }}" class="btn btn-secondary btn-sm">🖨️ QR Yazdır</a>
    <a href="{{ route('admin.tables.create') }}" class="btn btn-primary btn-sm">+ Yeni Masa</a>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            @if($tables->isEmpty())
                <div class="empty-state">
                    <div class="empty-state-icon">🪑</div>
                    <div class="empty-state-title">Henüz masa yok</div>
                    <p class="text-muted">Masa ekleyin, QR kodu kendiliğinden üretilir.</p>
                    <a href="{{ route('admin.tables.create') }}" class="btn btn-primary btn-sm">+ Yeni Masa</a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Masa</th>
                                <th>QR Kodu</th>
                                <th>Durum</th>
                                <th class="text-right">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tables as $table)
                                <tr>
                                    <td><strong>{{ $table->name }}</strong></td>
                                    <td>
                                        <code style="font-size: 0.8125rem;">{{ $table->qr_code }}</code>
                                    </td>
                                    <td>
                                        <span class="badge badge-{{ $table->is_active ? 'success' : 'danger' }}">
                                            {{ $table->is_active ? 'Açık' : 'Kapalı' }}
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('admin.tables.qr', $table) }}" class="btn btn-secondary btn-sm">QR</a>
                                        <a href="{{ route('admin.tables.edit', $table) }}" class="btn btn-secondary btn-sm">Düzenle</a>
                                        <form action="{{ route('admin.tables.toggle-status', $table) }}" method="POST" class="d-inline-block">
                                            @csrf
                                            <button type="submit" class="btn btn-secondary btn-sm">
                                                {{ $table->is_active ? 'Kapat' : 'Aç' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
