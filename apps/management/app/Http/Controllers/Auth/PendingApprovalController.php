<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PendingApprovalController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->isApproved()) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('Auth/PendingApproval', [
            'user' => [
                'name' => $user?->name,
                'email' => $user?->email,
                'approval_status' => $user?->approval_status,
                'auth_provider' => $user?->auth_provider,
            ],
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        $user = $request->user()?->fresh();

        $isApproved = $user ? $user->isApproved() : false;

        return response()->json([
            'is_approved' => $isApproved,
            'approval_status' => $user?->approval_status ?? 'pending',
            'redirect_url' => $isApproved ? route('admin.dashboard') : null,
        ]);
    }
}
