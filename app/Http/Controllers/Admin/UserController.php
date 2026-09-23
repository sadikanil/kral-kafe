<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\StudyGoal;
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
        return view('admin.users.create');
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $this->normalizePhone($request);

        // Dalga 18: telefon birincil kimlik; e-posta ve sifre istege bagli.
        // Sifre bos birakilirsa kullanici ilk giriste kendisi belirler.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$this->identityRules(),
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
        ], $this->identityMessages());

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'password' => filled($validated['password'] ?? null) ? Hash::make($validated['password']) : null,
            'role' => $validated['role'],
            'phone' => $validated['phone'] ?? null,
            'subscription_status' => 'active',
            'subscription_start' => now(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', 'Kullanıcı başarıyla oluşturuldu.');
    }

    /**
     * Show the form for editing a user.
     */
    public function edit(User $user)
    {
        return view('admin.users.edit', [
            'user' => $user,
            'weeklyGoal' => StudyGoal::activeFor($user, LocalDay::today()),
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
    private function normalizePhone(Request $request): void
    {
        if (filled($request->input('phone'))) {
            $request->merge([
                'phone' => Telefon::normalize($request->input('phone')) ?? $request->input('phone'),
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
