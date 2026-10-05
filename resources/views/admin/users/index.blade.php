@extends('layouts.app')

@section('title', 'Kullanıcılar - Kral Kafe')
@section('page-title', 'Kullanıcılar')

@section('page-actions')
    <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm">
        Yeni kullanıcı
    </a>
@endsection

@push('scripts')
<script>
    // Satir menusu: biri acilinca digerleri kapanir; disariya dokununca kapanir.
    (function () {
        const menuler = document.querySelectorAll('.row-menu');
        // Kutu sabit konumlu (tablo tasmasi kirpmasin): dugmenin altina,
        // sigmazsa ustune; saga hizali.
        function yerlestir(menu) {
            const dugme = menu.querySelector('summary').getBoundingClientRect();
            const kutu = menu.querySelector('.row-menu-list');
            const yukseklik = kutu.offsetHeight;
            const asagi = dugme.bottom + 4 + yukseklik <= window.innerHeight - 72;
            kutu.style.top = (asagi ? dugme.bottom + 4 : Math.max(8, dugme.top - 4 - yukseklik)) + 'px';
            kutu.style.left = Math.max(8, dugme.right - kutu.offsetWidth) + 'px';
        }

        function hepsiniKapat(haric) {
            menuler.forEach(function (m) { if (m !== haric) { m.open = false; } });
        }

        menuler.forEach(function (menu) {
            menu.addEventListener('toggle', function () {
                if (menu.open) { hepsiniKapat(menu); yerlestir(menu); }
            });
        });
        document.addEventListener('click', function (e) {
            menuler.forEach(function (m) { if (m.open && !m.contains(e.target)) { m.open = false; } });
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hepsiniKapat(null); } });
        // Kaydirinca kutu dugmeden kopmasin.
        window.addEventListener('scroll', function () { hepsiniKapat(null); }, { passive: true });
        document.querySelectorAll('.table-responsive').forEach(function (t) {
            t.addEventListener('scroll', function () { hepsiniKapat(null); }, { passive: true });
        });
    })();
</script>
@endpush

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
                            <tr class="row-link-row">
                                <td class="wrap-sm">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="sidebar-user-avatar hide-sm" style="width: 32px; height: 32px; font-size: 0.75rem;">
                                            {{ mb_strtoupper(mb_substr($user->name, 0, 2)) }}
                                        </div>
                                        <div>
                                            <a href="{{ route('admin.users.edit', $user) }}" class="text-inherit row-link">{{ $user->name }}</a>
                                            {{-- Telefonda gizlenen sutunlarin ozeti --}}
                                            <div class="show-sm text-muted" style="font-size: 0.75rem;">
                                                {{ $user->contactLabel() }} · {{ $user->displayRole()?->label() ?? $user->role }}
                                                @if($user->subscription_status !== 'active') · {{ $user->subscription_status === 'suspended' ? 'Askıda' : 'Pasif' }} @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="hide-sm">{{ $user->contactLabel() }}</td>
                                <td class="hide-sm">
                                    <span class="badge badge-{{ $user->displayRole()?->badgeClass() ?? 'info' }}">
                                        {{ $user->displayRole()?->label() ?? $user->role }}
                                    </span>
                                    {{-- Koc yetkili veli: etiket "Koc", velilik alt satirda. --}}
                                    @if($user->displayRole() === \App\Enums\Role::Coach && $user->coach_students_count)
                                        <small class="text-muted d-block">{{ $user->coach_students_count }} öğrenci{{ $user->coach_subject ? ' · ' . $user->coach_subject : '' }}</small>
                                    @endif
                                    @if($user->role() === \App\Enums\Role::Parent)
                                        <small class="text-muted d-block">{{ $user->is_coach ? 'Veli · ' : '' }}{{ $user->students_count }} çocuk</small>
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
                                {{--
                                    Satir islemleri (5 Ekim 2026): satirin tamami duzenlemeye
                                    gider (ad baglantisi satiri kaplar); diger islemler yazili
                                    adlarla "⋯" menusunde. Emojili dugmeler ne yaptigini
                                    soylemiyordu ve Duzenle'nin yanindaki ⏸️ yanlis dokunusa acikti.
                                --}}
                                <td class="actions-cell">
                                    <details class="row-menu">
                                        <summary class="btn btn-sm btn-secondary" aria-label="İşlemler: {{ $user->name }}">⋯</summary>
                                        <div class="row-menu-list">
                                            <a href="{{ route('admin.users.edit', $user) }}">Düzenle</a>
                                            @if($user->isStudent())
                                                <a href="{{ route('admin.subscriptions.index', $user) }}">Paket ve ödeme</a>
                                                <a href="{{ route('admin.exam-reports.index', $user) }}">Deneme raporları ve sonuç</a>
                                            @endif
                                            @if($user->id !== auth()->id())
                                                {{-- A5: onay soran ve adi soyleyen askiya alma. Ad @js ile:
                                                     kesme isaretli ad onay kutusunu bozmasin. --}}
                                                @php $askiyaAl = $user->subscription_status == 'active'; @endphp
                                                <form action="{{ route('admin.users.toggle-status', $user) }}" method="POST"
                                                    onsubmit="return confirm(@js($user->name . ($askiyaAl ? ' askıya alınsın mı?' : ' aktifleştirilsin mi?')))">
                                                    @csrf
                                                    <button type="submit" aria-label="{{ ($askiyaAl ? 'Askıya al: ' : 'Aktifleştir: ') . $user->name }}">
                                                        {{ $askiyaAl ? 'Askıya al' : 'Aktifleştir' }}
                                                    </button>
                                                </form>
                                                <form action="{{ route('admin.users.destroy', $user) }}" method="POST"
                                                    onsubmit="return confirm(@js($user->name . ' silinsin mi? Bu geri alınamaz.'))">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-danger" aria-label="Sil: {{ $user->name }}">Sil</button>
                                                </form>
                                            @endif
                                        </div>
                                    </details>
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

@push('scripts')
<script>
    // Satir menusu: biri acilinca digerleri kapanir; disariya dokununca kapanir.
    (function () {
        const menuler = document.querySelectorAll('.row-menu');
        // Kutu sabit konumlu (tablo tasmasi kirpmasin): dugmenin altina,
        // sigmazsa ustune; saga hizali.
        function yerlestir(menu) {
            const dugme = menu.querySelector('summary').getBoundingClientRect();
            const kutu = menu.querySelector('.row-menu-list');
            const yukseklik = kutu.offsetHeight;
            const asagi = dugme.bottom + 4 + yukseklik <= window.innerHeight - 72;
            kutu.style.top = (asagi ? dugme.bottom + 4 : Math.max(8, dugme.top - 4 - yukseklik)) + 'px';
            kutu.style.left = Math.max(8, dugme.right - kutu.offsetWidth) + 'px';
        }

        function hepsiniKapat(haric) {
            menuler.forEach(function (m) { if (m !== haric) { m.open = false; } });
        }

        menuler.forEach(function (menu) {
            menu.addEventListener('toggle', function () {
                if (menu.open) { hepsiniKapat(menu); yerlestir(menu); }
            });
        });
        document.addEventListener('click', function (e) {
            menuler.forEach(function (m) { if (m.open && !m.contains(e.target)) { m.open = false; } });
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hepsiniKapat(null); } });
        // Kaydirinca kutu dugmeden kopmasin.
        window.addEventListener('scroll', function () { hepsiniKapat(null); }, { passive: true });
        document.querySelectorAll('.table-responsive').forEach(function (t) {
            t.addEventListener('scroll', function () { hepsiniKapat(null); }, { passive: true });
        });
    })();
</script>
@endpush
