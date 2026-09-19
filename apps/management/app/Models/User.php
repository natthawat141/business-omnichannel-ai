<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\Contracts\OAuthenticatable;

#[Fillable(['name', 'email', 'auth_provider', 'password', 'is_admin', 'role', 'approval_status', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    protected $attributes = [
        'auth_provider' => 'password',
        'approval_status' => 'approved',
        'is_active' => true,
        'session_version' => 0,
    ];
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'session_version' => 'integer',
        ];
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved' && $this->is_active;
    }

    public function canViewBusiness(): bool
    {
        return $this->isApproved() && ($this->is_admin || in_array($this->role, ['editor', 'viewer'], true));
    }

    public function canEditBusiness(): bool
    {
        return $this->isApproved() && ($this->is_admin || $this->role === 'editor');
    }

    public function effectiveRole(): string
    {
        return $this->is_admin ? 'admin' : ($this->role ?? 'none');
    }

    public function hasGoogleAuth(): bool
    {
        return in_array($this->auth_provider, ['google', 'both'], true);
    }

    public function hasPasswordAuth(): bool
    {
        return in_array($this->auth_provider, ['password', 'both'], true);
    }

    public function authProviderLabel(): string
    {
        return match ($this->auth_provider) {
            'google' => 'Google เท่านั้น',
            'both' => 'Google + รหัสผ่าน',
            default => 'รหัสผ่านเท่านั้น',
        };
    }
}
