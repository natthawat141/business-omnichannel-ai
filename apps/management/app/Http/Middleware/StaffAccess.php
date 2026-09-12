<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user->is_active || (int) $request->session()->get('staff_version', 0) !== $user->session_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect('/login');
        }
        abort_unless($user->canViewBusiness(), 403);
        if (! $user->is_admin) {
            abort_if($request->is('admin/users*', 'admin/api-tokens*', 'admin/ai-setup*', 'admin/agent-changes*', 'admin/documents*'), 403);
            if (! $user->canEditBusiness()) {
                abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 403);
                abort_if($request->is('admin/*/create', 'admin/*/edit', 'admin/imports*', 'admin/business-profile'), 403);
            }
        }
        return $next($request);
    }
}
