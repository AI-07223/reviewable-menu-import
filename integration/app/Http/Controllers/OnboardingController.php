<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\OnboardingState;
use App\Support\ColorExtractor;
use App\Support\LogoProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// First-run onboarding wizard (/welcome). Owner-only, product/tenant-side —
// touches only the tenant `settings`/`users` tables, never a central/platform
// one (see the onboarding spec §7). O8: this now runs on routes/tenant.php
// behind InitializeTenancyBySubdomain, so url()/route() already resolve to
// the current request's tenant subdomain host — no extra Tenancy:: plumbing
// needed for storefront_url/login_url.
class OnboardingController extends Controller
{
    /** Readable staff passwords, e.g. "mango-till-ember-4721" — easy to read aloud or hand over. */
    private const PASSWORD_WORDS = [
        'mango', 'tiger', 'comet', 'maple', 'ember', 'coral', 'delta', 'breeze',
        'amber', 'cedar', 'falcon', 'harbor', 'lotus', 'pepper', 'saffron', 'till',
        'ginger', 'clove', 'basil', 'chutney',
    ];

    /** The full-screen React wizard shell. */
    public function show()
    {
        app(OnboardingState::class)->start();

        return view('onboarding');
    }

    /**
     * O8: the signed, single-use link App\Platform\Http\SignupController hands the
     * owner right after provisioning. Unauthenticated — sits outside the
     * auth/role:owner group in routes/tenant.php, protected instead by the
     * `signed` middleware (route registered with an `expires` param + HMAC
     * signature) plus this atomic claim of first_run_consumed_at, which is what
     * makes the link single-use: a signature can still be valid (unexpired) on
     * a second visit, but no owner row will be eligible to claim anymore.
     */
    public function firstRun(Request $request)
    {
        $owner = User::query()->where('role', 'owner')->whereNull('first_run_consumed_at')->first();

        abort_unless($owner !== null, 404);

        $claimed = User::query()->whereKey($owner->id)->whereNull('first_run_consumed_at')
            ->update(['first_run_consumed_at' => now()]);

        // Lost a race against a concurrent visit to the same link — the other
        // request wins the login, this one gets a plain 404 (not a partial login).
        abort_unless($claimed === 1, 404);

        Auth::login($owner);
        $request->session()->regenerate();

        return redirect('/welcome');
    }

    /** Everything the island needs to render — one request. */
    public function state(Request $request)
    {
        $onboarding = app(OnboardingState::class);
        $onboarding->maybeAutoComplete();

        $s = Setting::current();

        return response()->json([
            'owner_name' => $request->user()->name,
            'restaurant_name' => $s->upi_name ?: null,
            'logo_url' => $s->logo_path ? $this->logoUrl($s->logo_path) : null,
            'accent_color' => $s->accent_color,
            'demo' => \Tenancy::isDemo(),
            'onboarding' => $onboarding->state(),
            'progress' => $onboarding->progress(),
            'business' => [
                'address' => $s->address,
                'hours' => $s->hours,
                'phone' => $s->phone,
                'upi_id' => $s->upi_id,
                'upi_name' => $s->upi_name,
            ],
            // url() resolves against the current request's host, which — on
            // routes/tenant.php, behind InitializeTenancyBySubdomain — is
            // already this tenant's own subdomain.
            'storefront_url' => url('/'),
            'login_url' => url('/login'),
        ]);
    }

    /**
     * Logo step: resize + store the file, then rank up to 5 accent candidates,
     * mirroring ManageUpi::save(). Persists ONLY logo_path — never accent_color,
     * so a greyscale re-upload can't silently wipe out a colour the owner already
     * chose (that write happens exclusively in chooseAccent()/the /welcome/theme
     * endpoint). Deletes the previous logo file once the new one is committed,
     * so re-uploads don't leak orphaned files on the public disk.
     *
     * 20MB is a generous ceiling on the raw upload, not the stored size — every
     * upload is downscaled to ≤512px and re-encoded via LogoProcessor before it
     * ever reaches disk, so even a big phone photo lands as a lean PNG (tens of
     * KB). The mimes rule still narrows the upload to real bitmap images.
     */
    public function uploadLogo(Request $request)
    {
        // Demo lock (FIX 4): same reasoning as ManageUpi::save()'s logo guard —
        // the logo rebrands every screen for every demo visitor. Never let a
        // demo upload reach disk through this path either.
        abort_if(\Tenancy::isDemo(), 403, 'Branding is locked in the demo — sign up to upload your own logo.');

        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,webp|max:20480'], [
            'logo.image' => "That file isn't a photo or image we can read — please upload a PNG, JPG or WEBP.",
            'logo.mimes' => "That file isn't a photo or image we can read — please upload a PNG, JPG or WEBP.",
            'logo.max' => 'That image is too large — please upload a smaller PNG, JPG or WEBP.',
        ]);

        $old = Setting::current()->logo_path;

        $resized = LogoProcessor::resize($request->file('logo'));
        $path = 'logos/'.Str::random(40).'.png';
        if (! Storage::disk('public')->put($path, $resized)) {
            throw ValidationException::withMessages([
                'logo' => 'The logo could not be saved. Please try again.',
            ]);
        }
        $candidates = ColorExtractor::palette(Storage::disk('public')->path($path), 5);

        try {
            Setting::current()->update(['logo_path' => $path]);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return response()->json([
            'logo_url' => $this->logoUrl($path),
            'accent' => $candidates[0] ?? null,
            'candidates' => $candidates,
        ]);
    }

    // WP1.4: the public disk is tenant-suffixed, so a logo's URL must go through
    // tenant_asset() whenever a tenant is bound — mirrors ManageUpi::logoUrl().
    private function logoUrl(string $path): string
    {
        return \Tenancy::check() ? tenant_asset($path) : Storage::disk('public')->url($path);
    }

    /** "Pick a shade" — greyscale logo, or the owner skipped the upload entirely. */
    public function chooseAccent(Request $request)
    {
        $data = $request->validate(['accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);

        Setting::current()->update(['accent_color' => $data['accent']]);

        return response()->json(['ok' => true]);
    }

    /** Business-details step — writes the same `settings` columns ManageUpi does. */
    public function saveBusiness(Request $request)
    {
        $data = $request->validate([
            'address' => 'nullable|string|max:1000',
            'hours' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'upi_id' => 'nullable|string|max:255',
            'upi_name' => 'nullable|string|max:255',
        ]);

        foreach ($data as $key => $value) {
            $data[$key] = trim((string) $value) ?: null;
        }

        // Same demo freeze as ManageUpi::save() — never duplicate the lock logic,
        // just apply it identically here. Per-tenant (Tenancy::isDemo()): a real
        // tenant's onboarding must never be blocked from setting its own UPI ID.
        if (\Tenancy::isDemo()) {
            foreach (['phone', 'upi_id', 'upi_name'] as $key) {
                unset($data[$key]);
            }
        }

        Setting::current()->update($data);
        app(OnboardingState::class)->advance('business', 'done', 6);

        return response()->json(['ok' => true]);
    }

    /** Staff step: create a login + a readable, shareable password. */
    public function createStaff(Request $request)
    {
        // Demo lock (FIX 2b): a demo-login visitor gets full owner access, and
        // users created here would pollute the seeded role roster until the
        // next reset. Same per-tenant Tenancy::isDemo() key as saveBusiness()'s
        // freeze above and UserResource's admin-side staff lock — the demo
        // tenant's staff list is read-only everywhere.
        abort_if(\Tenancy::isDemo(), 403, 'Staff accounts are locked in the demo — sign up to manage your own team.');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|in:cashier,kitchen,waiter',
        ]);

        $base = Str::slug($data['name'], '') ?: 'staff';
        $base = mb_substr($base, 0, 20) ?: 'staff';
        $username = $base;
        $renamed = false;
        $n = 1;
        while (User::where('username', $username)->exists()) {
            $n++;
            $username = $base.$n;
            $renamed = true;
        }

        // 3 words (20 choices each) + a 4-digit number ≈ 72M combos — up from the old
        // word-word-NN's ~36k, which a login-rate-limited-but-not-locked-out attacker
        // could exhaust. Still readable/typeable/shareable aloud.
        $password = self::PASSWORD_WORDS[array_rand(self::PASSWORD_WORDS)].'-'
            .self::PASSWORD_WORDS[array_rand(self::PASSWORD_WORDS)].'-'
            .self::PASSWORD_WORDS[array_rand(self::PASSWORD_WORDS)].'-'
            .random_int(1000, 9999);

        // The exists() check above is check-then-insert, not atomic — two concurrent
        // requests can both pass it for the same username and race to the unique
        // index. Retry with the next numeric suffix instead of 500ing.
        $user = null;
        for ($attempt = 0; $attempt < 5 && $user === null; $attempt++) {
            try {
                $user = User::create([
                    'name' => $data['name'],
                    'username' => $username,
                    'role' => $data['role'],
                    'password' => Hash::make($password),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $n++;
                $username = $base.$n;
                $renamed = true;
            }
        }

        abort_if($user === null, 500, 'Could not create a unique login — please try again.');

        app(OnboardingState::class)->advance('staff', 'done', 7);

        return response()->json([
            'username' => $username,
            'password' => $password,
            'renamed' => $renamed,
            'role' => $user->role,
            'login_url' => url('/login'),
        ]);
    }

    /** Generic step progress: {step, action: done|skip, current_step?}. */
    public function markStep(Request $request)
    {
        $data = $request->validate([
            'step' => 'nullable|string|in:menu,logo,business,staff',
            'action' => 'required|in:done,skip',
            'current_step' => 'nullable|integer|min:1|max:7',
        ]);

        app(OnboardingState::class)->advance($data['step'] ?? null, $data['action'], $data['current_step'] ?? null);

        return response()->json(['ok' => true]);
    }

    /** Step 7 — close the loop. */
    public function finish()
    {
        app(OnboardingState::class)->complete();

        return response()->json(['ok' => true]);
    }

    /** Dashboard checklist "Dismiss" — plain form POST from Blade, not the island. */
    public function dismiss(Request $request)
    {
        app(OnboardingState::class)->dismiss();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
