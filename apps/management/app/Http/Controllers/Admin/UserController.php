<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['all', 'pending', 'active', 'inactive'])],
        ]);

        $query = User::query();

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'));
        }

        $status = $filters['status'] ?? 'all';
        if ($status === 'pending') {
            $query->where('approval_status', 'pending');
        } elseif ($status === 'active') {
            $query->where('approval_status', 'approved')->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where(fn ($q) => $q->where('is_active', false)->orWhere('approval_status', 'rejected'));
        }

        $users = $query->orderBy('id', 'desc')->paginate(20)->withQueryString()
            ->through(fn (User $user) => $this->summary($user));

        $pendingCount = User::query()->where('approval_status', 'pending')->count();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'pendingCount' => $pendingCount,
        ]);
    }

    public function create()
    {
        return Inertia::render('Users/Form', ['member' => null, 'events' => []]);
    }

    public function edit(User $user)
    {
        return Inertia::render('Users/Form', ['member' => $this->summary($user),
            'events' => DB::table('user_management_events')->where('target_id', $user->id)
                ->orderByDesc('id')->limit(20)->get(['id', 'actor_id', 'event', 'created_at']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = DB::transaction(function () use ($request, $data) {
            $user = User::create($data + [
                'password' => Str::random(64),
                'auth_provider' => 'password',
                'approval_status' => $data['is_active'] ? 'approved' : 'rejected',
            ]);
            $this->audit($request, $user, 'created');
            return $user;
        });
        return redirect()->route('admin.users.edit', $user)->with('success', 'เพิ่มผู้ใช้แล้ว สร้างลิงก์ตั้งรหัสผ่านเพื่อส่งให้ผู้ใช้เป็นการส่วนตัว');
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        DB::transaction(function () use ($request, $user, $data) {
            // Lock the stable first account to serialize all role/status changes, including competing demotions.
            User::orderBy('id')->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_admin && $actor->is_active && $actor->session_version === $request->user()->session_version, 403);
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $admins = User::where('is_admin', true)->where('is_active', true)->lockForUpdate()->get();
            if ($target->is_admin && $target->is_active && (! $data['is_admin'] || ! $data['is_active']) && $admins->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'ต้องมีผู้ดูแลระบบที่ใช้งานได้อย่างน้อย 1 คน']);
            }
            $securityChange = $target->effectiveRole() !== $data['role']
                || $target->is_active !== $data['is_active'] || $target->email !== $data['email'];
            if ($securityChange) {
                $target->session_version++;
                $target->remember_token = Str::random(60);
                ApiToken::where('user_id', $target->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                Password::deleteToken($target);
            }
            if ($data['is_active'] && $target->approval_status !== 'approved') {
                $target->approval_status = 'approved';
            }
            $target->fill($data)->save();
            $this->audit($request, $target, $securityChange ? 'access_changed' : 'profile_updated');
        }, 3);
        return redirect()->route('admin.users.edit', $user)->with('success', 'บันทึกผู้ใช้แล้ว');
    }

    public function approve(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])],
        ]);

        DB::transaction(function () use ($request, $user, $data) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_admin && $actor->is_active && $actor->session_version === $request->user()->session_version, 403);

            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $target->approval_status = 'approved';
            $target->is_active = true;
            $target->role = $data['role'];
            $target->is_admin = $data['role'] === 'admin';
            $target->session_version++;
            $target->save();

            $this->audit($request, $target, 'approved');
        });

        return redirect()->back()->with('success', 'อนุมัติสิทธิ์ผู้ใช้งานเรียบร้อยแล้ว');
    }

    public function reject(Request $request, User $user)
    {
        DB::transaction(function () use ($request, $user) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_admin && $actor->is_active && $actor->session_version === $request->user()->session_version, 403);

            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($target->is_admin && $target->is_active) {
                $admins = User::where('is_admin', true)->where('is_active', true)->lockForUpdate()->get();
                if ($admins->count() <= 1) {
                    throw ValidationException::withMessages(['role' => 'ต้องมีผู้ดูแลระบบที่ใช้งานได้อย่างน้อย 1 คน']);
                }
            }

            $target->approval_status = 'rejected';
            $target->is_active = false;
            $target->session_version++;
            $target->remember_token = Str::random(60);
            $target->save();

            $this->audit($request, $target, 'rejected');
        });

        return redirect()->back()->with('success', 'ปฏิเสธคำขอเข้าใช้งานเรียบร้อยแล้ว');
    }

    public function passwordLink(Request $request, User $user)
    {
        abort_unless($user->is_active, 422, 'เปิดใช้งานบัญชีก่อนสร้างลิงก์');
        $token = Password::createToken($user);
        $this->audit($request, $user, 'password_link_created');
        return response()->json(['url' => route('password.reset').'#'.http_build_query(['token' => $token, 'email' => $user->email]),
            'expires_minutes' => config('auth.passwords.users.expire', 60),
        ])->header('Cache-Control', 'no-store');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['is_admin'] = $data['role'] === 'admin';
        $data['is_active'] = (bool) $data['is_active'];
        return $data;
    }

    private function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->effectiveRole(),
            'is_active' => (bool) $user->is_active,
            'auth_provider' => (string) ($user->auth_provider ?? 'password'),
            'approval_status' => (string) ($user->approval_status ?? 'approved'),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function audit(Request $request, User $user, string $event): void
    {
        DB::table('user_management_events')->insert(['actor_id' => $request->user()->id,
            'target_id' => $user->id, 'event' => $event, 'created_at' => now()]);
    }
}
