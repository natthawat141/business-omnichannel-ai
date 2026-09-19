<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Attempt to authenticate, enforcing a per-email+IP throttle.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password') + ['is_active' => true], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            $user = User::where('email', Str::lower(trim((string) $this->string('email'))))->first();
            if ($user && $user->auth_provider === 'google') {
                throw ValidationException::withMessages([
                    'email' => __('บัญชีนี้ลงทะเบียนด้วย Google เท่านั้น กรุณาเข้าสู่ระบบด้วยปุ่ม "เข้าสู่ระบบด้วย Google" หรือติดต่อผู้ดูแลระบบเพื่อขอลิงก์ตั้งรหัสผ่าน'),
                ]);
            }

            throw ValidationException::withMessages([
                'email' => __('อีเมลหรือรหัสผ่านไม่ถูกต้อง'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        $this->session()->put('staff_version', Auth::user()->session_version);
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('พยายามเข้าสู่ระบบมากเกินไป กรุณาลองใหม่ใน :seconds วินาที', ['seconds' => $seconds]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
