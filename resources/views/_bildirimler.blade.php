{{--
    Bildirim listesi (Dalga 11). Ogrenci ve veli panelinde ayni.

    Teslim kanali su an yalnizca panel: kullanici haberi ancak buraya
    baktiginda aliyor. E-posta gelince ayni kayitlar gonderilecek ve bu liste
    gecmis olarak kalacak - kayit ile teslim bastan ayri tutuldu.
--}}
@if($notifications->isNotEmpty())
    <h2>Bildirimler</h2>

    @foreach($notifications as $bildirim)
        <div class="session-card mb-2">
            <strong>{{ $bildirim->title }}</strong>
            @if($bildirim->body)
                <div class="text-muted">{{ $bildirim->body }}</div>
            @endif
            <div class="text-muted">
                {{ $bildirim->created_at->timezone(config('kafe.timezone'))->format('d.m.Y H:i') }}
            </div>
        </div>
    @endforeach
@endif
