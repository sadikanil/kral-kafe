<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Telefon;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Giris (Dalga 18). Kimlik once telefon, yoksa e-posta. Sifre alani
 * gelmediyse bu yalnizca birinci adimdir: kimlik taninir, hangi adimin
 * acilacagina karar verilir.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kimlik' => ['required', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'kimlik.required' => $this->input('ile') === 'eposta'
                ? 'E-posta adresinizi girin.'
                : 'Telefon numaranızı girin.',
        ];
    }

    /** Birinci adim mi (yalnizca kimlik), yoksa sifre de geldi mi. */
    public function isIdentityStep(): bool
    {
        return ! $this->has('password');
    }

    /**
     * Kimlige karsilik gelen kullanici. Telefon tek bicimde saklandigi icin
     * once normalize edilir; '@' iceriyorsa e-posta sayilir.
     *
     * @throws ValidationException
     */
    public function identifiedUser(): User
    {
        // Mesaj ekranin moduna gore: e-posta ekraninda '@' unutana telefon
        // bicimi tarif etmek yanlis alani isaret ederdi.
        $kimlik = $this->normalizedIdentity()
            ?? throw ValidationException::withMessages([
                'kimlik' => $this->input('ile') === 'eposta'
                    ? 'Geçerli bir e-posta adresi girin.'
                    : 'Geçerli bir cep telefonu numarası girin (05XX XXX XX XX).',
            ]);

        $kullanici = str_contains($kimlik, '@')
            ? User::where('email', $kimlik)->first()
            : User::where('phone', $kimlik)->first();

        return $kullanici ?? throw ValidationException::withMessages([
            'kimlik' => 'Bu bilgiyle kayıtlı bir kullanıcı yok.',
        ]);
    }

    /**
     * Kimligin tek bicimi: e-posta kirpilmis ve kucuk harf, telefon
     * Telefon::normalize. Arama da kilit anahtari da BURADAN okur; ikisi ayri
     * yazildiginda ayni numarayi her seferinde baska bosluklarla yazan biri
     * kilide hic takilmadan sinirsiz sifre deneyebiliyordu.
     *
     * null: e-posta degil ve gecerli bir cep telefonu da degil.
     */
    private function normalizedIdentity(): ?string
    {
        $kimlik = trim($this->string('kimlik'));

        return str_contains($kimlik, '@') ? Str::lower($kimlik) : Telefon::normalize($kimlik);
    }

    /**
     * Sifreyle giris. Sifresi hic olmayan hesap burada ASLA gecmez - onun
     * yolu sifre belirleme adimi.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $kullanici = $this->identifiedUser();
        $sifre = (string) $this->input('password');

        $gecti = $kullanici->password !== null
            && $sifre !== ''
            && Auth::getProvider()->validateCredentials($kullanici, ['password' => $sifre]);

        if (! $gecti) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'kimlik' => __('auth.failed'),
            ]);
        }

        Auth::login($kullanici, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /** @throws ValidationException */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'kimlik' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Kilit HESAP + IP basina sayilir. Gecersiz kimlik ham haliyle kalabilir:
     * identifiedUser() onu sifre denenmeden reddeder, sayaca hic yazilmaz.
     */
    public function throttleKey(): string
    {
        $hesap = $this->normalizedIdentity() ?? Str::lower(trim($this->string('kimlik')));

        return Str::transliterate($hesap . '|' . $this->ip());
    }
}
