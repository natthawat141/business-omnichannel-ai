<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
{
    public function create()
    {
        return response()->view('auth.reset-password')
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(12), 'max:255'],
        ]);
        $status = DB::transaction(function () use ($data) {
            // Lock account before token validation/deletion so concurrent reuse cannot win twice.
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            if (! $user || ! $user->is_active) {
                return Password::INVALID_TOKEN;
            }
            return Password::reset($data, function (User $user, string $password) {
                $user->password = $password;
                $user->remember_token = Str::random(60);
                $user->session_version++;
                $user->save();
                ApiToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                DB::table('user_management_events')->insert(['actor_id' => null, 'target_id' => $user->id,
                    'event' => 'password_set', 'created_at' => now()]);
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'ลิงก์ไม่ถูกต้อง หมดอายุ หรือถูกใช้แล้ว กรุณาขอลิงก์ใหม่']);
        }
        if ($request->expectsJson()) {
            return response()->json(['redirect' => '/login'])->header('Cache-Control', 'no-store');
        }
        return redirect('/login')->with('success', 'ตั้งรหัสผ่านแล้ว กรุณาเข้าสู่ระบบ');
    }
}
