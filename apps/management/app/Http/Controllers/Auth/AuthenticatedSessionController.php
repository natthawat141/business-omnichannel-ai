<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request): \Symfony\Component\HttpFoundation\Response
    {
        $request->authenticate();

        $request->session()->regenerate();

        $redirect = redirect()->intended(route('admin.dashboard'));
        // OAuth consent is a full HTML page, not an Inertia component.
        if ($request->header('X-Inertia') && parse_url($redirect->getTargetUrl(), PHP_URL_PATH) === '/oauth/authorize') {
            return Inertia::location($redirect->getTargetUrl());
        }
        return $redirect;
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
