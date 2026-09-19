<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\FirebaseTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FirebaseLoginController extends Controller
{
    public function __construct(
        private readonly FirebaseTokenVerifier $tokenVerifier,
    ) {}

    public function store(Request $request): JsonResponse|RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $claims = $this->tokenVerifier->verify($validated['id_token']);
        $email = $claims['email'];

        if (blank($email)) {
            throw ValidationException::withMessages([
                'email' => 'ไม่พบบัญชีอีเมลในบัญชี Firebase นี้',
            ]);
        }

        $email = Str::lower(trim((string) $email));
        $user = User::query()->where('email', $email)->first();

        // Auto-register new Google user with pending approval
        if (! $user) {
            $name = ! empty($claims['name']) ? trim((string) $claims['name']) : explode('@', $email)[0];

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(64),
                'role' => 'viewer',
                'is_admin' => false,
                'is_active' => false,
                'approval_status' => 'pending',
                'auth_provider' => 'google',
                'email_verified_at' => now(),
            ]);

            DB::table('user_management_events')->insert([
                'actor_id' => null,
                'target_id' => $user->id,
                'event' => 'google_registered_pending',
                'created_at' => now(),
            ]);
        } else {
            // Existing user logged in with Google; ensure provider recorded
            if ($user->auth_provider !== 'google') {
                $user->update(['auth_provider' => 'google']);
            }
        }

        // Account is pending administrator approval
        if ($user->approval_status === 'pending') {
            Auth::login($user, $request->boolean('remember'));
            $request->session()->regenerate();
            $request->session()->put('staff_version', $user->session_version);

            if ($request->wantsJson() && ! $request->header('X-Inertia')) {
                return response()->json([
                    'success' => true,
                    'redirect_url' => route('pending-approval'),
                ]);
            }

            return redirect()->route('pending-approval');
        }

        // Account is rejected or deactivated
        if ($user->approval_status === 'rejected' || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'บัญชีผู้ใช้นี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();
        $request->session()->put('staff_version', $user->session_version);

        $redirect = redirect()->intended(route('admin.dashboard'));
        if ($request->header('X-Inertia') && parse_url($redirect->getTargetUrl(), PHP_URL_PATH) === '/oauth/authorize') {
            return Inertia::location($redirect->getTargetUrl());
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'redirect_url' => route('admin.dashboard'),
            ]);
        }

        return $redirect;
    }
}
