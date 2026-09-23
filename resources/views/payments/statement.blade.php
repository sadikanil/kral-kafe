@extends('layouts.app')

@section('title', 'Ödemeler - Kral Kafe')
@section('page-title', auth()->user()->is($student) ? 'Ödemelerim' : 'Ödemeler · ' . $student->name)

{{-- Dalga 22: ay ay paket bedeli + adisyon dokumu. Salt okunur. --}}
@php $para = fn ($t) => \App\Services\PaymentStatement::money($t); @endphp

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="{{ route($route, $routeParams + ['ay' => $neighbours['prev']]) }}" class="btn btn-secondary btn-sm">‹ Önceki</a>
        <strong>{{ $monthLabel }}</strong>
        <a href="{{ route($route, $routeParams + ['ay' => $neighbours['next']]) }}" class="btn btn-secondary btn-sm">Sonraki ›</a>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h4>Paket</h4></div>
        <div class="card-body">
            @forelse($statement->subscriptions() as $abonelik)
                <div class="d-flex justify-content-between align-items-center mb-2" style="flex-wrap: wrap; gap: .5rem;">
                    <div>
                        <strong>{{ $abonelik->package->name }}</strong>
                        <span class="text-muted">· {{ $abonelik->formattedPrice() }}</span>
                        <div class="text-muted" style="font-size:.85rem">
                            Ödenen {{ $para($abonelik->payments->sum('amount')) }} · Kalan {{ $abonelik->formattedBalance() }}
                            · Vade {{ $abonelik->dueOn()->format('d.m.Y') }}
                        </div>
                    </div>
                    <span class="badge badge-{{ $abonelik->payment_status->badgeClass() }}">{{ $abonelik->payment_status->label() }}</span>
                </div>
            @empty
                <p class="text-muted mb-0">Bu ay başlayan paket yok.</p>
            @endforelse
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h4>Adisyon</h4></div>
        <div class="card-body p-0">
            @if($statement->consumptions()->isEmpty())
                <div class="p-4 text-center text-muted">Bu ay adisyon yok.</div>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <tbody>
                            @foreach($statement->consumptions() as $kayit)
                                <tr>
                                    <td class="text-muted">{{ $kayit->consumed_at->timezone(config('kafe.timezone'))->format('d.m') }}</td>
                                    <td>{{ $kayit->product->name }}{{ $kayit->quantity > 1 ? ' ×' . $kayit->quantity : '' }}</td>
                                    <td class="text-right">
                                        @if((float) $kayit->total_price === 0.0 && $kayit->covered_quantity > 0)
                                            <span class="text-muted">pakete dahil</span>
                                        @else
                                            {{ $kayit->formatted_total }}
                                            @if($kayit->covered_quantity > 0)
                                                <div class="text-muted" style="font-size:.8rem">{{ $kayit->covered_quantity }} adedi pakete dahil</div>
                                            @endif
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

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between"><span>Paket</span><span>{{ $para($statement->packageTotal()) }}</span></div>
            <div class="d-flex justify-content-between"><span>Adisyon</span><span>{{ $para($statement->spendingTotal()) }}</span></div>
            <hr>
            <div class="d-flex justify-content-between"><strong>Ay toplamı</strong><strong>{{ $para($statement->monthTotal()) }}</strong></div>
            @if($statement->packageBalance() > 0)
                <div class="d-flex justify-content-between text-danger mt-1"><span>Paketten kalan borç</span><span>{{ $para($statement->packageBalance()) }}</span></div>
            @endif
        </div>
    </div>
@endsection
