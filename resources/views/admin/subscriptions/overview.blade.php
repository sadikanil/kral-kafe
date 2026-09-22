@extends('layouts.admin')

@section('title', 'Ödemeler - Kral Kafe')
@section('page-title', 'Ödemeler')

@section('content')
    @php $durumlar = \App\Enums\PaymentStatus::cases(); @endphp
    <div class="d-flex gap-2 mb-3" style="flex-wrap: wrap;">
        <a href="{{ route('admin.subscriptions.overview') }}" class="btn btn-sm {{ $filter ? 'btn-secondary' : 'btn-primary' }}">Tümü</a>
        @foreach($durumlar as $d)
            <a href="{{ route('admin.subscriptions.overview', ['durum' => $d->value]) }}" class="btn btn-sm {{ $filter === $d->value ? 'btn-primary' : 'btn-secondary' }}">
                {{ $d->label() }} ({{ $counts[$d->value] ?? 0 }})
            </a>
        @endforeach
    </div>

    <div class="card">
        <div class="card-body p-0">
            @if($subscriptions->isEmpty())
                <div class="p-4 text-center text-muted">Kayıt yok.</div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Öğrenci</th><th>Paket</th><th>Dönem</th><th>Tutar</th><th>Ödenen</th><th>Kalan</th><th>Vade</th><th>Durum</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($subscriptions as $abonelik)
                                <tr>
                                    <td><strong>{{ $abonelik->student?->name ?? '—' }}</strong></td>
                                    <td>{{ $abonelik->package?->name ?? '—' }}</td>
                                    <td>{{ $abonelik->starts_on->format('d.m.Y') }} – {{ $abonelik->ends_on->format('d.m.Y') }}</td>
                                    <td>{{ $abonelik->formattedPrice() }}</td>
                                    <td>{{ number_format($abonelik->paidTotal(), 2, ',', '.') }} ₺</td>
                                    <td><strong>{{ $abonelik->formattedBalance() }}</strong></td>
                                    <td>{{ $abonelik->isCancelled() ? '—' : $abonelik->dueOn()->format('d.m.Y') }}</td>
                                    <td><span class="badge badge-{{ $abonelik->payment_status->badgeClass() }}">{{ $abonelik->payment_status->label() }}</span></td>
                                    <td class="text-right">
                                        @if($abonelik->student)
                                            <a href="{{ route('admin.subscriptions.index', $abonelik->student) }}" class="btn btn-secondary btn-sm">Aç</a>
                                        @endif
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
