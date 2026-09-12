<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $tokens = ApiToken::query()
            ->where('prefix', '!=', 'oauth')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'prefix' => $token->prefix,
                'abilities' => $token->abilities ?? [],
                'is_protected' => $token->is_protected,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'revoked_at' => $token->revoked_at?->toIso8601String(),
            ]);

        return Inertia::render('ApiTokens/Index', [
            'tokens' => $tokens,
            // One-time plaintext, surfaced only immediately after creation.
            'newToken' => $request->session()->get('plainToken'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_days' => ['nullable', 'integer', 'min:1'],
        ]);

        $expiresAt = ! empty($validated['expires_days'])
            ? now()->addDays((int) $validated['expires_days'])
            : null;

        ['plainText' => $plainText, 'token' => $token] = ApiToken::issue($validated['name'], ['read'], $expiresAt);
        $token->forceFill(['user_id' => $request->user()->id])->save();

        return redirect()->route('admin.api-tokens.index')
            ->with('plainToken', $plainText)
            ->with('success', 'สร้างโทเคนเรียบร้อยแล้ว โปรดคัดลอกและเก็บไว้อย่างปลอดภัย');
    }

    public function destroy(Request $request, ApiToken $token): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_if($token->prefix === 'oauth', 404);

        if ($token->is_protected) {
            return redirect()->route('admin.api-tokens.index')
                ->with('error', 'โทเคนระบบกำลังถูกใช้งาน จึงไม่สามารถเพิกถอนจากหน้าเว็บได้');
        }

        $token->revoked_at = now();
        $token->save();

        return redirect()->route('admin.api-tokens.index')
            ->with('success', 'เพิกถอนโทเคนเรียบร้อยแล้ว');
    }
}
