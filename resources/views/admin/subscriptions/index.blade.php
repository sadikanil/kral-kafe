@extends('layouts.app')

@section('title', 'Paket ve Ödeme - ' . $student->name)
@section('page-title', 'Paket ve Ödeme: ' . $student->name)

@section('topbar-actions')
    <a href="{{ route('admin.users.edit', $student) }}" class="btn btn-secondary btn-sm">Kullanıcıya dön</a>
@endsection

@section('content')
    <div class="card mb-3" style="max-width: 640px;">
        <div class="card-header"><h4>Paket ata</h4></div>
        <div class="card-body">
            @if($packages->isEmpty())
                <p class="text-muted mb-0">Satışta paket yok. Önce <a href="{{ route('admin.packages.create') }}">paket tanımlayın</a>.</p>
            @else
                <form action="{{ route('admin.subscriptions.store', $student) }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <label for="package_id" class="form-label">Paket *</label>
                        <select id="package_id" name="package_id" class="form-control @error('package_id') is-invalid @enderror" required
                            onchange="document.getElementById('price').value = this.options[this.selectedIndex].dataset.price">
                            @foreach($packages as $paket)
                                <option value="{{ $paket->id }}" data-price="{{ $paket->monthly_price }}" {{ (string) old('package_id') === (string) $paket->id ? 'selected' : '' }}>
                                    {{ $paket->name }} — {{ $paket->formattedPrice() }}/ay
                                </option>
                            @endforeach
                        </select>
                        @error('package_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                    <div class="d-flex gap-2">
                        <div class="form-group" style="flex: 1;">
                            <label for="starts_on" class="form-label">Başlangıç *</label>
                            <input type="date" id="starts_on" name="starts_on" required class="form-control @error('starts_on') is-invalid @enderror"
                                value="{{ old('starts_on', $defaultStart) }}">
                            @error('starts_on')<span class="invalid-feedback">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="ends_on" class="form-label">Bitiş</label>
                            <input type="date" id="ends_on" name="ends_on" class="form-control @error('ends_on') is-invalid @enderror" value="{{ old('ends_on') }}">
                            @error('ends_on')<span class="invalid-feedback">{{ $message }}</span>@enderror
                            <small class="text-muted">Boşsa bir ay.</small>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="price" class="form-label">Tutar (₺)</label>
                            <input type="number" id="price" name="price" step="0.01" min="0" class="form-control @error('price') is-invalid @enderror"
                                value="{{ old('price', $packages->first()->monthly_price) }}">
                            @error('price')<span class="invalid-feedback">{{ $message }}</span>@enderror
                            <small class="text-muted">Bu aboneliğe sabitlenir.</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="note" class="form-label">Not</label>
                        <input type="text" id="note" name="note" maxlength="255" class="form-control" value="{{ old('note') }}">
                    </div>
                    <button type="submit" class="btn btn-primary">Paketi ata</button>
                </form>
            @endif
        </div>
    </div>

    @forelse($subscriptions as $abonelik)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center" style="flex-wrap: wrap; gap: 8px;">
                <div>
                    <h4 class="mb-0">🎫 {{ $abonelik->package->name }}</h4>
                    <small class="text-muted">
                        {{ $abonelik->starts_on->format('d.m.Y') }} – {{ $abonelik->ends_on->format('d.m.Y') }}
                        · Tutar {{ $abonelik->formattedPrice() }} · Kalan <strong>{{ $abonelik->formattedBalance() }}</strong>
                        @if(!$abonelik->isCancelled() && $abonelik->balance() > 0) · Vade {{ $abonelik->dueOn()->format('d.m.Y') }} @endif
                        @if($abonelik->note) · {{ $abonelik->note }} @endif
                    </small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge badge-{{ $abonelik->payment_status->badgeClass() }}">{{ $abonelik->payment_status->label() }}</span>
                    @if(!$abonelik->isCancelled())
                        <form action="{{ route('admin.subscriptions.cancel', $abonelik) }}" method="POST" class="d-inline-block"
                            onsubmit="return confirm('Abonelik iptal edilsin mi?');">
                            @csrf
                            <button type="submit" class="btn btn-danger btn-sm">İptal</button>
                        </form>
                    @endif
                </div>
            </div>
            <div class="card-body">
                @if($abonelik->payments->isNotEmpty())
                    <div class="table-responsive mb-3">
                        <table class="table">
                            <thead><tr><th>Tarih</th><th>Tutar</th><th>Yöntem</th><th>Not</th><th></th></tr></thead>
                            <tbody>
                                @foreach($abonelik->payments->sortByDesc('paid_at') as $odeme)
                                    <tr>
                                        <td>{{ $odeme->paid_at->format('d.m.Y') }}</td>
                                        <td>{{ $odeme->formattedAmount() }}</td>
                                        <td>{{ $odeme->method->label() }}</td>
                                        <td class="text-muted">{{ $odeme->note }}</td>
                                        <td class="text-right">
                                            <form action="{{ route('admin.subscriptions.payments.destroy', $odeme) }}" method="POST" class="d-inline-block"
                                                onsubmit="return confirm('Ödeme kaydı silinsin mi?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-secondary btn-sm">Sil</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if(!$abonelik->isCancelled())
                    <form action="{{ route('admin.subscriptions.payments.store', $abonelik) }}" method="POST" class="d-flex gap-2 align-items-end" style="flex-wrap: wrap;">
                        @csrf
                        <div class="form-group mb-0">
                            <label class="form-label" for="amount_{{ $abonelik->id }}">Ödeme (₺)</label>
                            <input type="number" id="amount_{{ $abonelik->id }}" name="amount" step="0.01" min="0.01" required class="form-control" style="width: 130px;"
                                value="{{ $abonelik->balance() > 0 ? $abonelik->balance() : '' }}">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label" for="paid_at_{{ $abonelik->id }}">Tarih</label>
                            <input type="date" id="paid_at_{{ $abonelik->id }}" name="paid_at" required class="form-control" value="{{ \App\Support\LocalDay::today() }}">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label" for="method_{{ $abonelik->id }}">Yöntem</label>
                            <select id="method_{{ $abonelik->id }}" name="method" class="form-control">
                                @foreach(\App\Enums\PaymentMethod::cases() as $y)
                                    <option value="{{ $y->value }}">{{ $y->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group mb-0" style="flex: 1; min-width: 140px;">
                            <label class="form-label" for="note_{{ $abonelik->id }}">Not</label>
                            <input type="text" id="note_{{ $abonelik->id }}" name="note" maxlength="255" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-success">Ödeme kaydet</button>
                    </form>
                    @error('amount')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                    @error('paid_at')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                @endif
            </div>
        </div>
    @empty
        <div class="card"><div class="card-body text-center text-muted p-4">Bu öğrenciye henüz paket atanmadı.</div></div>
    @endforelse
@endsection
