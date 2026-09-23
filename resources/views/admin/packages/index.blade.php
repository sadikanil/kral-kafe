@extends('layouts.admin')

@section('title', 'Paketler - Kral Kafe')
@section('page-title', 'Paketler')

@section('topbar-actions')
    <a href="{{ route('admin.packages.create') }}" class="btn btn-primary btn-sm">+ Yeni Paket</a>
@endsection

@section('content')
    <div class="card">
        <div class="card-body p-0">
            @if($packages->isEmpty())
                <div class="empty-state">
                    <div class="empty-state-icon">🎫</div>
                    <div class="empty-state-title">Henüz paket yok</div>
                    <p class="text-muted">İlk paketi tanımlayın; öğrencilere kullanıcı listesindeki 💳 düğmesinden atanır.</p>
                    <a href="{{ route('admin.packages.create') }}" class="btn btn-primary btn-sm">+ Yeni Paket</a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Paket</th><th>Aylık Fiyat</th><th>Kapsam</th><th>Abonelik</th><th>Durum</th><th class="text-right">İşlem</th></tr>
                        </thead>
                        <tbody>
                            @foreach($packages as $paket)
                                <tr>
                                    <td>
                                        <strong>{{ $paket->name }}</strong>
                                        @if($paket->tier)<span class="badge badge-info">Tier {{ $paket->tier }}</span>@endif
                                        @if($paket->is_addon)<span class="badge badge-warning">Ek paket</span>@endif
                                        @if($paket->description)<br><small class="text-muted">{{ $paket->description }}</small>@endif
                                    </td>
                                    <td>{{ $paket->formattedPrice() }}</td>
                                    <td style="font-size: 0.8125rem;">
                                        @if($paket->has_reserved_table) 🪑 Rezerve masa<br> @endif
                                        @if($paket->includes_coaching) 🧭 Koçluk<br> @endif
                                        @if($paket->includes_exam_club) 📝 Deneme kulübü<br> @endif
                                        @if($paket->includes_private_lessons) 👨‍🏫 Özel ders<br> @endif
                                        @if($paket->weekly_mock_exams) 📝 Haftada {{ $paket->weekly_mock_exams }} deneme<br> @endif
                                        @foreach($paket->items as $kalem)
                                            ☕ {{ $kalem->product->name }} — {{ $kalem->label() }}<br>
                                        @endforeach
                                    </td>
                                    <td>{{ $paket->subscriptions_count }}</td>
                                    <td>
                                        <span class="badge badge-{{ $paket->is_active ? 'success' : 'danger' }}">{{ $paket->is_active ? 'Satışta' : 'Kapalı' }}</span>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('admin.packages.edit', $paket) }}" class="btn btn-secondary btn-sm">Düzenle</a>
                                        <form action="{{ route('admin.packages.toggle-status', $paket) }}" method="POST" class="d-inline-block">
                                            @csrf
                                            <button type="submit" class="btn btn-secondary btn-sm">{{ $paket->is_active ? 'Kapat' : 'Aç' }}</button>
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
    <div class="alert alert-info mt-3">
        💡 Fiyatı değiştirmek mevcut abonelikleri ve geçmiş faturaları <strong>değiştirmez</strong>; fiyat atama anında aboneliğe kopyalanır.
    </div>
@endsection
