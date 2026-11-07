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
                {{-- Bolume gore gruplu (7 Kasim 2026). Durum saklanmaz: acik
                     oturum varsa "Dolu" (oturan uye adiyla). --}}
                @foreach($groups as $bolum => $masalar)
                    @if($groups->count() > 1 || $bolum !== '')
                        <h4 class="mb-2 {{ $loop->first ? '' : 'mt-3' }}">{{ $bolum !== '' ? $bolum : 'Bölümsüz' }} <span class="text-muted">({{ $masalar->count() }})</span></h4>
                    @endif
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
                                @foreach($masalar as $table)
                                    @php $oturan = $occupants->get($table->id); @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $table->name }}</strong>
                                            @if($table->assignedStudent)
                                                <div class="text-muted" style="font-size: 0.8125rem;">Sabit: {{ $table->assignedStudent->name }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <code style="font-size: 0.8125rem;">{{ $table->qr_code }}</code>
                                        </td>
                                        <td>
                                            @if(! $table->is_active)
                                                <span class="badge badge-danger">Kapalı</span>
                                            @elseif($oturan)
                                                <span class="badge badge-warning">Dolu</span>
                                                <div class="text-muted" style="font-size: 0.8125rem;">{{ $oturan->student->name }}</div>
                                            @else
                                                <span class="badge badge-success">Boş</span>
                                            @endif
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
                @endforeach
            @endif
        </div>
    </div>
@endsection
