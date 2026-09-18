<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\FirebaseTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => 'อีเมล '.$email.' ยังไม่ได้รับสิทธิ์เข้าใช้งานระบบ กรุณาติดต่อผู้ดูแลระบบ',
            ]);
        }

        if (! $user->is_active) {
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
