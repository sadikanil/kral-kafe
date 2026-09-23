@extends('layouts.app')

@section('title', 'Kullanıcılar - Kral Kafe')
@section('page-title', 'Kullanıcılar')

@section('topbar-actions')
    <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm">
        ➕ Yeni Kullanıcı
    </a>
@endsection

@section('content')
    <!-- Filtreler -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="d-flex gap-2" style="flex-wrap: wrap;">
                <input type="text" name="search" class="form-control" placeholder="İsim veya e-posta ara..." value="{{ request('search') }}" style="max-width: 250px;">
                
                <select name="role" class="form-control" style="max-width: 150px;">
                    <option value="all">Tüm Roller</option>
                    @foreach (\App\Enums\Role::cases() as $rol)
                        <option value="{{ $rol->value }}" {{ request('role') === $rol->value ? 'selected' : '' }}>{{ $rol->label() }}</option>
                    @endforeach
                </select>
                
                <select name="status" class="form-control" style="max-width: 150px;">
                    <option value="all">Tüm Durumlar</option>
                    <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Aktif</option>
                    <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Pasif</option>
                    <option value="suspended" {{ request('status') == 'suspended' ? 'selected' : '' }}>Askıda</option>
                </select>
                
                <button type="submit" class="btn btn-secondary">Filtrele</button>
                <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">Temizle</a>
            </form>
        </div>
    </div>
    
    <!-- Kullanıcı Tablosu -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>İsim</th>
                            <th>Telefon / E-posta</th>
                            <th>Rol</th>
                            <th>Durum</th>
                            <th>Kayıt Tarihi</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="sidebar-user-avatar" style="width: 32px; height: 32px; font-size: 0.75rem;">
                                            {{ strtoupper(substr($user->name, 0, 2)) }}
                                        </div>
                                        <span>{{ $user->name }}</span>
                                    </div>
                                </td>
                                <td>{{ $user->contactLabel() }}</td>
                                <td>
                                    <span class="badge badge-{{ $user->role()?->badgeClass() ?? 'info' }}">
                                        {{ $user->role()?->label() ?? $user->role }}
                                    </span>
                                    @if($user->role() === \App\Enums\Role::Parent)
                                        <small class="text-muted d-block">{{ $user->students_count }} öğrenci</small>
                                    @endif
                                </td>
                                <td>
                                    @switch($user->subscription_status)
                                        @case('active')
                                            <span class="badge badge-success">Aktif</span>
                                            @break
                                        @case('inactive')
                                            <span class="badge badge-warning">Pasif</span>
                                            @break
                                        @case('suspended')
                                            <span class="badge badge-danger">Askıda</span>
                                            @break
                                    @endswitch
                                </td>
                                <td>{{ $user->created_at->format('d.m.Y') }}</td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-secondary">✏️</a>
                                        @if($user->isStudent())
                                            <a href="{{ route('admin.subscriptions.index', $user) }}" class="btn btn-sm btn-secondary" title="Paket ve ödeme">💳</a>
                                            <a href="{{ route('admin.exam-reports.index', $user) }}" class="btn btn-sm btn-secondary" title="Deneme raporları">📄</a>
                                        @endif
                                        
                                        @if($user->id !== auth()->id())
                                            <form action="{{ route('admin.users.toggle-status', $user) }}" method="POST" class="d-inline-block">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-{{ $user->subscription_status == 'active' ? 'warning' : 'success' }}" title="{{ $user->subscription_status == 'active' ? 'Askıya Al' : 'Aktifleştir' }}">
                                                    {{ $user->subscription_status == 'active' ? '⏸️' : '▶️' }}
                                                </button>
                                            </form>
                                            
                                            <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="d-inline-block" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center p-4 text-muted">
                                    Kullanıcı bulunamadı.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        
        @if($users->hasPages())
            <div class="card-footer">
                {{ $users->withQueryString()->links() }}
            </div>
        @endif
    </div>
@endsection
