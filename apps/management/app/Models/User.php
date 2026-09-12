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

#[Fillable(['name', 'email', 'password', 'is_admin', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    protected $attributes = ['is_active' => true, 'session_version' => 0];
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

    public function canViewBusiness(): bool
    {
        return $this->is_active && ($this->is_admin || in_array($this->role, ['editor', 'viewer'], true));
    }

    public function canEditBusiness(): bool
    {
        return $this->is_active && ($this->is_admin || $this->role === 'editor');
    }

    public function effectiveRole(): string
    {
        return $this->is_admin ? 'admin' : ($this->role ?? 'none');
    }
}
