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
                {{-- Suzgec satirinda gorunur etiket yer tutar; ad aria-label ile
                     (placeholder yazinca kaybolur, ekran okuyucuya ad degildir). --}}
                <input type="search" name="search" class="form-control" enterkeyhint="search"
                    aria-label="Ad, telefon ya da e-posta ara" placeholder="Ad, telefon ya da e-posta…"
                    value="{{ request('search') }}" style="max-width: 250px;">

                <select name="role" class="form-control" aria-label="Rol" style="max-width: 150px;">
                    <option value="all">Tüm Roller</option>
                    @foreach (\App\Enums\Role::cases() as $rol)
                        <option value="{{ $rol->value }}" {{ request('role') === $rol->value ? 'selected' : '' }}>{{ $rol->label() }}</option>
                    @endforeach
                </select>
                
                <select name="status" class="form-control" aria-label="Durum" style="max-width: 150px;">
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
                            <th class="hide-sm">Telefon / E-posta</th>
                            <th class="hide-sm">Rol</th>
                            <th class="hide-sm">Durum</th>
                            <th class="hide-sm">Kayıt Tarihi</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td class="wrap-sm">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="sidebar-user-avatar hide-sm" style="width: 32px; height: 32px; font-size: 0.75rem;">
                                            {{ mb_strtoupper(mb_substr($user->name, 0, 2)) }}
                                        </div>
                                        <div>
                                            <a href="{{ route('admin.users.edit', $user) }}" class="text-inherit">{{ $user->name }}</a>
                                            {{-- Telefonda gizlenen sutunlarin ozeti --}}
                                            <div class="show-sm text-muted" style="font-size: 0.75rem;">
                                                {{ $user->contactLabel() }} · {{ $user->role()?->label() ?? $user->role }}
                                                @if($user->subscription_status !== 'active') · {{ $user->subscription_status === 'suspended' ? 'Askıda' : 'Pasif' }} @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="hide-sm">{{ $user->contactLabel() }}</td>
                                <td class="hide-sm">
                                    <span class="badge badge-{{ $user->role()?->badgeClass() ?? 'info' }}">
                                        {{ $user->role()?->label() ?? $user->role }}
                                    </span>
                                    @if($user->role() === \App\Enums\Role::Parent)
                                        <small class="text-muted d-block">{{ $user->students_count }} öğrenci</small>
                                    @endif
                                </td>
                                <td class="hide-sm">
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
                                <td class="hide-sm">{{ $user->created_at->timezone(config('kafe.timezone'))->format('d.m.Y') }}</td>
                                <td class="actions-cell">
                                    <div class="row-actions">
                                        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-secondary" title="Düzenle" aria-label="Düzenle">✏️</a>
                                        @if($user->isStudent())
                                            <a href="{{ route('admin.subscriptions.index', $user) }}" class="btn btn-sm btn-secondary" title="Paket ve ödeme" aria-label="Paket ve ödeme">💳</a>
                                            <a href="{{ route('admin.exam-reports.index', $user) }}" class="btn btn-sm btn-secondary" title="Deneme raporları" aria-label="Deneme raporları">📄</a>
                                        @endif
                                        
                                        @if($user->id !== auth()->id())
                                            {{-- A5: ⏸️ Duzenle'nin hemen yaninda ve dokunmatikte title
                                                 gorunmuyor; yanlis dokunus ogrenciyi aninda askiya aliyordu.
                                                 Ad @js ile: kesme isaretli ad onay kutusunu bozmasin. --}}
                                            @php $askiyaAl = $user->subscription_status == 'active'; @endphp
                                            <form action="{{ route('admin.users.toggle-status', $user) }}" method="POST" class="d-inline-block"
                                                onsubmit="return confirm(@js($user->name . ($askiyaAl ? ' askıya alınsın mı?' : ' aktifleştirilsin mi?')))">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-{{ $askiyaAl ? 'warning' : 'success' }}"
                                                    title="{{ $askiyaAl ? 'Askıya al' : 'Aktifleştir' }}"
                                                    aria-label="{{ ($askiyaAl ? 'Askıya al: ' : 'Aktifleştir: ') . $user->name }}">
                                                    {{ $askiyaAl ? '⏸️' : '▶️' }}
                                                </button>
                                            </form>
                                            
                                            <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="d-inline-block" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger" title="Sil" aria-label="Sil">🗑️</button>
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
