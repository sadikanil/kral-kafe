<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\StudyGoal;
use App\Services\SubscriptionOpener;
use App\Models\User;
use App\Support\LocalDay;
use App\Support\Telefon;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Filter by role
        if ($request->has('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        // Filter by subscription status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('subscription_status', $request->status);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $like = SqlDialect::likeOperator(DB::connection()->getDriverName());
            // Telefon rakam olarak saklaniyor; "0532 123" gibi yazilan arama
            // bastaki sifir ve bosluklardan arindirilir.
            $rakamlar = ltrim(preg_replace('/\D/', '', $search), '0');

            $query->where(function ($q) use ($search, $like, $rakamlar) {
                $q->where('name', $like, "%{$search}%")
                    ->orWhere('email', $like, "%{$search}%");

                if ($rakamlar !== '') {
                    $q->orWhere('phone', $like, "%{$rakamlar}%");
                }
            });
        }

        // Veli satirinda bagli ogrenci sayisi gorunur (Dalga 6).
        $users = $query->withCount('students')->orderBy('name')->paginate(20);

        return view('admin.users.index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create()
    {
        return view('admin.users.create', [
            'packages' => Package::active()->where('is_addon', false)->orderBy('tier')->orderBy('name')->get(),
            'addons' => Package::active()->where('is_addon', true)->orderBy('name')->get(),
            'coaches' => User::whereIn('role', [Role::Coach->value, Role::Admin->value])->orderBy('name')->get(),
            'parents' => User::where('role', Role::Parent->value)->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request, SubscriptionOpener $abonelikler)
    {
        $this->normalizePhone($request);
        $this->normalizePhone($request, 'new_parent_phone');

        // Dalga 18: telefon birincil kimlik; e-posta ve sifre istege bagli.
        // Sifre bos birakilirsa kullanici ilk giriste kendisi belirler.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$this->identityRules(),
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
        ], $this->identityMessages());

        $ogrenciMi = $validated['role'] === Role::Student->value;
        $ogrenciVerisi = $ogrenciMi ? $this->validateStudentOnboarding($request) : null;

        // Hepsi ya da hicbiri: paket, koc ya da veli yazilamazsa ogrenci de
        // olusmamali - velisiz ya da paketsiz ogrenci butunlugu bozar.
        DB::transaction(function () use ($validated, $ogrenciVerisi, $abonelikler) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'password' => filled($validated['password'] ?? null) ? Hash::make($validated['password']) : null,
                'role' => $validated['role'],
                'phone' => $validated['phone'] ?? null,
                'subscription_status' => 'active',
                'subscription_start' => now(),
            ]);

            if ($ogrenciVerisi !== null) {
                $this->onboardStudent($user, $ogrenciVerisi, $abonelikler);
            }
        });

        return redirect()->route('admin.users.index')
            ->with('success', 'Kullanıcı başarıyla oluşturuldu.');
    }

    /**
     * Dalga 20: ogrenci = paket + (koclukluysa) koc + en az bir veli.
     *
     * @return array{package:Package,addons:\Illuminate\Support\Collection<int,Package>,coach_id:?int,parent_ids:array<int,int>,new_parent:?array{name:string,phone:string}}
     */
    private function validateStudentOnboarding(Request $request): array
    {
        $veri = $request->validate([
            // exists kurali false'u BOS METNE cevirir ve hicbir satir eslesmez;
            // bu yuzden 0/1.
            'package_id' => ['required', 'integer',
                Rule::exists('packages', 'id')->where('is_active', 1)->where('is_addon', 0)],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['integer',
                Rule::exists('packages', 'id')->where('is_active', 1)->where('is_addon', 1)],
            'coach_id' => ['nullable', 'integer',
                Rule::exists('users', 'id')->whereIn('role', [Role::Coach->value, Role::Admin->value])],
            'parent_ids' => ['nullable', 'array'],
            'parent_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', Role::Parent->value)],
            'new_parent_name' => ['nullable', 'string', 'max:255'],
            'new_parent_phone' => ['nullable', 'required_with:new_parent_name', 'regex:/^5\d{9}$/',
                Rule::unique('users', 'phone'), 'different:phone'],
        ], [
            'package_id.required' => 'Öğrenci için paket seçin.',
            'package_id.exists' => 'Ana paket olarak satıştaki bir paket seçin (ek paket olamaz).',
            'coach_id.exists' => 'Seçilen kişi koç ya da yönetici değil.',
            'parent_ids.*.exists' => 'Seçilen kişi veli değil.',
            'new_parent_phone.required_with' => 'Yeni velinin telefonu gerekli.',
            'new_parent_phone.regex' => 'Geçerli bir cep telefonu girin (05XX XXX XX XX).',
            'new_parent_phone.unique' => 'Bu telefon başka bir kullanıcıda kayıtlı; listeden seçin.',
            'new_parent_phone.different' => 'Velinin telefonu öğrencininkiyle aynı olamaz.',
        ]);

        $paket = Package::findOrFail($veri['package_id']);
        $ekler = Package::whereIn('id', $veri['addon_ids'] ?? [])->get();
        $kocluk = $paket->includes_coaching || $ekler->contains('includes_coaching', true);

        if ($kocluk && empty($veri['coach_id'])) {
            throw ValidationException::withMessages(['coach_id' => 'Bu paket koçluk içeriyor; bir koç seçin.']);
        }

        if (empty($veri['parent_ids']) && empty($veri['new_parent_name'])) {
            throw ValidationException::withMessages(['parent_ids' => 'Öğrencinin en az bir velisi olmalı.']);
        }

        return [
            'package' => $paket,
            'addons' => $ekler,
            // Koclugu kapsamayan pakette koc ATANMAZ (formdan gelse bile).
            'coach_id' => $kocluk ? (int) $veri['coach_id'] : null,
            'parent_ids' => array_map('intval', $veri['parent_ids'] ?? []),
            'new_parent' => empty($veri['new_parent_name']) ? null
                : ['name' => $veri['new_parent_name'], 'phone' => $veri['new_parent_phone']],
        ];
    }

    private function onboardStudent(User $ogrenci, array $veri, SubscriptionOpener $abonelikler): void
    {
        $bugun = Carbon::parse(LocalDay::today());

        $abonelikler->open($ogrenci, $veri['package'], $bugun);
        foreach ($veri['addons'] as $ek) {
            $abonelikler->open($ogrenci, $ek, $bugun);
        }

        if ($veri['coach_id'] !== null) {
            $ogrenci->coaches()->attach($veri['coach_id'], ['created_by' => auth()->id()]);
        }

        $veliler = $veri['parent_ids'];
        if ($veri['new_parent'] !== null) {
            // Sifresiz: veli ilk giriste kendisi belirler (Dalga 18).
            $veliler[] = User::create([
                'name' => $veri['new_parent']['name'],
                'phone' => $veri['new_parent']['phone'],
                'role' => Role::Parent->value,
                'subscription_status' => 'active',
            ])->id;
        }

        $ogrenci->parents()->attach(array_unique($veliler));
    }

    /**
     * Show the form for editing a user.
     */
    public function edit(User $user)
    {
        // Ozel ders (Dalga 25): yalnizca paketi kapsayan ogrencide.
        $ozelDers = $user->isStudent() && $user->entitlements()->privateLessons;
        $bugun = LocalDay::today();

        return view('admin.users.edit', [
            'user' => $user,
            'lessonSlots' => $ozelDers ? $user->privateLessonSlots : null,
            'upcomingLessons' => $ozelDers
                ? \App\Support\PrivateLessonCalendar::between($user, $bugun, Carbon::parse($bugun)->addWeeks(4)->toDateString())
                : [],
            'weeklyGoal' => StudyGoal::activeFor($user, LocalDay::today()),
            // Paket (Dalga 30a): simdiki + degistirme formu (ekler haric).
            'currentSubscription' => $user->isStudent() ? $user->currentSubscription() : null,
            'switchPackages' => \App\Models\Package::active()->where('is_addon', false)->orderBy('name')->get(),
            // Koc atamasi (Dalga 14). Plan formu bu ekrandan /koc/plan
            // altina TASINDI; burada kalan yalnizca "kim izliyor" sorusu.
            // Ayni formu iki yerde tutmak, birinin gunun birinde
            // digerinden farkli davranmasi demekti.
            'assignedCoaches' => $user->hasRole(Role::Student)
                ? $user->coaches()->orderBy('name')->get()
                : collect(),
            'assignableCoaches' => $user->hasRole(Role::Student)
                ? User::whereIn('role', [Role::Coach->value, Role::Admin->value])
                    ->orderBy('name')
                    ->get()
                : collect(),
            // Veli-ogrenci bagi (Dalga 6). Liste yalnizca KAYITLI role gore
            // gelir: veliye ogrenci listesi, ogrenciye veli listesi. Rol bu
            // formda degistiriliyorsa bag bir sonraki duzenlemede kurulur.
            'linkableStudents' => $user->hasRole(Role::Parent)
                ? User::where('role', Role::Student->value)->orderBy('name')->get()
                : collect(),
            'linkableParents' => $user->hasRole(Role::Student)
                ? User::where('role', Role::Parent->value)->orderBy('name')->get()
                : collect(),
            'linkedStudentIds' => $user->students()->pluck('users.id')->all(),
            'linkedParentIds' => $user->parents()->pluck('users.id')->all(),
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        $this->normalizePhone($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$this->identityRules($user),
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
            'subscription_status' => ['required', 'in:active,inactive,suspended'],
            'weekly_goal_hours' => ['nullable', 'integer', 'min:1', 'max:120'],
            // Bag yalnizca dogru rollere kurulabilir: bir veliyi "ogrenci"
            // diye baglamak paneli bos birakir, hata vermez. exists kurali
            // rolu de kontrol ediyor ki yanlis bag hic dogmasin.
            'student_ids' => ['sometimes', 'nullable', 'array'],
            'student_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', Role::Student->value)],
            'parent_ids' => ['sometimes', 'nullable', 'array'],
            'parent_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', Role::Parent->value)],
        ], $this->identityMessages());

        // Dalga 20: ogrencinin son velisi kaldirilamaz. Anahtar formda yoksa
        // bag degismiyor demektir (bolum o rol icin cizilmemis).
        if ($validated['role'] === Role::Student->value
            && $request->has('parent_ids') && empty($validated['parent_ids'])) {
            throw ValidationException::withMessages(['parent_ids' => 'Öğrencinin en az bir velisi olmalı.']);
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'role' => $validated['role'],
            'subscription_status' => $validated['subscription_status'],
            'phone' => $validated['phone'] ?? null,
        ]);

        if (!empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        $this->syncWeeklyGoal($user, $validated['weekly_goal_hours'] ?? null);

        // Anahtar formda yoksa baga DOKUNULMAZ (bolum o rol icin cizilmemistir).
        // Anahtar var ama bos ise hepsi kaldirilir: formdaki gizli alan bunu
        // saglar, yoksa "hicbirini secme" ile "bolum yoktu" ayirt edilemezdi.
        if ($request->has('student_ids')) {
            $this->syncLinks($user->students(), $validated['student_ids'] ?? []);
        }

        if ($request->has('parent_ids')) {
            $this->syncLinks($user->parents(), $validated['parent_ids'] ?? []);
        }

        return redirect()->route('admin.users.index')
            ->with('success', 'Kullanıcı başarıyla güncellendi.');
    }

    /**
     * Bagi verilen listeyle eslestirir.
     *
     * sync() yerine attach/detach: sync mevcut satirlarin created_by'ini da
     * ezerdi; bagi ilk kuran kisi kayitta kalmali.
     *
     * @param  list<int|string>  $ids
     */
    private function syncLinks(BelongsToMany $bag, array $ids): void
    {
        $istenen = array_values(array_unique(array_map('intval', $ids)));
        $mevcut = $bag->pluck('users.id')->all();

        $bag->detach(array_values(array_diff($mevcut, $istenen)));
        $bag->attach(
            array_values(array_diff($istenen, $mevcut)),
            ['created_by' => auth()->id()]
        );
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user)
    {
        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Kendinizi silemezsiniz.');
        }

        // Dalga 20: tek velisi bu kisi olan ogrenci velisiz kalmasin.
        $yetim = $user->students()->withCount('parents')->get()->where('parents_count', 1);
        if ($yetim->isNotEmpty()) {
            return back()->with('error', 'Bu veli silinemez: ' . $yetim->pluck('name')->join(', ')
                . ' için tek veli. Önce öğrenciye başka bir veli bağlayın.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'Kullanıcı başarıyla silindi.');
    }

    /**
     * Haftalik hedefi gunceller.
     *
     * Mevcut hedefin UZERINE YAZMAZ: eskisini bugunden kapatip yenisini acar.
     * Uzerine yazmak gecmisi sessizce degistirirdi - gecen hafta 10 saatlik
     * hedefi tutturan ogrenci, hedef 25 saate cikinca "tutturamamis" gorunur.
     */
    private function syncWeeklyGoal(User $user, ?int $hours): void
    {
        if ($hours === null) {
            return;
        }

        $dakika = $hours * 60;
        $bugun = LocalDay::today();
        $mevcut = StudyGoal::activeFor($user, $bugun);

        if ($mevcut && $mevcut->target_minutes === $dakika) {
            return;
        }

        $mevcut?->supersedeOn($bugun);

        StudyGoal::create([
            'student_id' => $user->id,
            'period' => 'weekly',
            'target_minutes' => $dakika,
            'effective_from' => $bugun,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Toggle user subscription status.
     */
    public function toggleStatus(User $user)
    {
        $newStatus = $user->subscription_status === 'active' ? 'suspended' : 'active';
        $user->update(['subscription_status' => $newStatus]);

        $statusText = $newStatus === 'active' ? 'aktifleştirildi' : 'askıya alındı';

        return back()->with('success', "Kullanıcı aboneliği {$statusText}.");
    }

    /**
     * Dalga 18b: sifre silinir. Kullanici bir sonraki isteginde her
     * cihazdan atilir (EndPasswordlessSession) ve giriste yeni sifre
     * belirler. remember_token da degisir ki "beni hatirla" cerezi geri
     * sokmasin.
     */
    public function resetPassword(User $user)
    {
        // Kendi sifresini silen yonetici, baska yonetici yoksa sistemi kilitler.
        if ($user->is(auth()->user())) {
            return back()->with('error', 'Kendi şifreni buradan sıfırlayamazsın.');
        }

        $user->forceFill([
            'password' => null,
            'remember_token' => \Illuminate\Support\Str::random(60),
        ])->save();

        return back()->with('success', "{$user->name} çıkış yaptırıldı; sonraki girişte yeni şifre belirleyecek.");
    }

    /**
     * Telefon tek bicimde saklanir (App\Support\Telefon). Gecersiz girdi
     * OLDUGU GIBI birakilir ki asagidaki kural onu reddedebilsin.
     */
    private function normalizePhone(Request $request, string $alan = 'phone'): void
    {
        if (filled($request->input($alan))) {
            $request->merge([
                $alan => Telefon::normalize($request->input($alan)) ?? $request->input($alan),
            ]);
        }
    }

    /** Telefon ya da e-postadan en az biri; ikisi de tekil. */
    private function identityRules(?User $user = null): array
    {
        return [
            'phone' => ['nullable', 'required_without:email', 'regex:/^5\d{9}$/',
                Rule::unique('users', 'phone')->ignore($user)],
            'email' => ['nullable', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user)],
        ];
    }

    private function identityMessages(): array
    {
        return [
            'phone.required_without' => 'Telefon numarası gerekli (e-posta yoksa).',
            'phone.regex' => 'Geçerli bir cep telefonu girin (05XX XXX XX XX).',
            'phone.unique' => 'Bu telefon numarası başka bir kullanıcıda kayıtlı.',
        ];
    }
}
