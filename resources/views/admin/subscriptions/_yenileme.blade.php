{{--
    Donemin yenileme durumu ve ac/kapat dugmesi (18 Kas 2026).
    Beklenen: $abonelik (Subscription).
--}}
@php
    $sonraki = $abonelik->renewal;
    $degisebilir = ! $sonraki && $abonelik->canToggleRenewal();
@endphp
@unless($abonelik->isCancelled())
    <div class="d-flex align-items-center gap-2 mb-0" style="flex-wrap: wrap;">
        @if($sonraki)
            <span class="text-muted">Yenilendi → {{ $sonraki->starts_on->format('d.m.Y') }} – {{ $sonraki->ends_on->format('d.m.Y') }}</span>
        @elseif($abonelik->auto_renew)
            <span class="badge badge-success">Her ay yenilenir</span>
            <span class="text-muted">
                yeni dönem {{ $abonelik->renewsOn()->format('d.m.Y') }}
                @if($abonelik->next_package_id)
                    @php [$p, $t] = $abonelik->nextTerms(); @endphp
                    · sonraki dönemden <strong>{{ $p->name }}</strong> · {{ number_format((float) $t, 2, ',', '.') }} ₺
                @endif
            </span>
        @elseif($degisebilir)
            <span class="badge badge-warning">Yenilenmeyecek</span>
            <span class="text-muted">{{ $abonelik->ends_on->format('d.m.Y') }} tarihinde biter</span>
        @else
            <span class="text-muted">Yenilenmedi</span>
        @endif

        @if($degisebilir)
            <form action="{{ route('admin.subscriptions.renewal', $abonelik) }}" method="POST" class="d-inline-block"
                  @if($abonelik->auto_renew) data-confirm="Yenileme durdurulsun mu? Paket {{ $abonelik->ends_on->format('d.m.Y') }} tarihinde biter." @endif>
                @csrf
                <input type="hidden" name="auto_renew" value="{{ $abonelik->auto_renew ? 0 : 1 }}">
                <button type="submit" class="btn btn-sm {{ $abonelik->auto_renew ? 'btn-secondary' : 'btn-primary' }}">
                    {{ $abonelik->auto_renew ? 'Yenilemeyi durdur' : 'Her ay yenile' }}
                </button>
            </form>
        @endif
    </div>
@endunless
