<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bildirimler sayfasi (Dalga 27). Zilin "Tumu" baglantisi. Acilinca
 * kullanicinin okunmamis bildirimleri okundu sayilir - yalnizca kendisinin.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $liste = Notification::for($request->user())->latest()->limit(50)->get();

        Notification::for($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return view('notifications.index', ['notifications' => $liste]);
    }
}
