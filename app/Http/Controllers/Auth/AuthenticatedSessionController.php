<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Giris (Dalga 18): once telefon, sonra sifre ya da sifre belirleme.
 *
 * Adimlar arasinda sunucuda durum TUTULMUYOR; kimlik bir sonraki forma salt
 * okunur (gorunur) alan olarak tasiniyor - sifre yoneticileri kullanici adini
 * oradan okur. Guvenlik o alana degil, sunucudaki kontrole bagli:
 * sifre belirleme yalnizca sifresi HIC olmayan hesapta calisir.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View
    {
        // Hatali gonderimden donuste adim, formun gizli alanindan gelir.
        $adim = session('giris_adimi', old('adim', 'kimlik'));
        $kimlik = session('giris_kimlik', old('kimlik'));

        return view('auth.login', [
            'adim' => in_array($adim, ['sifre', 'belirle'], true) ? $adim : 'kimlik',
            'kimlik' => $kimlik,
            // E-posta klavyesi: baglantidan (?ile=eposta), hata donusunde
            // formun gizli alanindan ya da girilen degerin kendisinden.
            'epostaIle' => $request->query('ile', old('ile')) === 'eposta'
                || str_contains((string) $kimlik, '@'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if ($request->isIdentityStep()) {
            $kullanici = $request->identifiedUser();

            return redirect()->route('login')->with([
                'giris_adimi' => $kullanici->password === null ? 'belirle' : 'sifre',
                'giris_kimlik' => $kullanici->phone ?? $kullanici->email,
            ]);
        }

        $request->authenticate();

        return $this->signedIn($request);
    }

    /** Yeni kullanicinin ilk girisi: sifresini kendisi belirler. */
    public function setPassword(LoginRequest $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'password.min' => 'Şifre en az 6 karakter olmalı.',
            'password.confirmed' => 'İki şifre birbiriyle aynı değil.',
        ]);

        $kullanici = $request->identifiedUser();

        if ($kullanici->password !== null) {
            throw ValidationException::withMessages([
                'kimlik' => 'Bu hesabın şifresi zaten var. Şifreyle giriş yapın.',
            ]);
        }

        $kullanici->update(['password' => $request->input('password')]);

        Auth::login($kullanici, true);

        return $this->signedIn($request);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function signedIn(Request $request): RedirectResponse
    {
        $request->session()->regenerate();

        // Rol basina inis sayfasi App\Enums\Role::homeRoute() icinde tanimli.
        return redirect()->intended(route(Auth::user()->homeRoute()));
    }
}
