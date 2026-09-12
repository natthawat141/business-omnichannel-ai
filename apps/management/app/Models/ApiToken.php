<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = [
        'name',
        'token_hash',
        'prefix',
        'abilities',
        'is_protected',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'is_protected' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'token_hash',
    ];

    protected static function booted(): void
    {
        static::updated(function (ApiToken $token): void {
            // Revocation takes effect for new API calls immediately. Pending
            // proposals are retained for audit, but cannot later be approved
            // or applied until an administrator resolves the trust issue.
            if ($token->wasChanged('revoked_at') && $token->revoked_at !== null) {
                AgentChangeSet::query()
                    ->where('api_token_id', $token->id)
                    ->whereIn('status', [AgentChangeSet::PROPOSED, AgentChangeSet::APPROVED])
                    ->update(['status' => AgentChangeSet::SUSPENDED, 'suspended_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    /**
     * Issue a new token. Returns the model plus the ONE-TIME plaintext value.
     * Only the SHA-256 hash is persisted.
     *
     * @param  array<int, string>  $abilities
     * @return array{token: ApiToken, plainText: string}
     */
    public static function issue(string $name, array $abilities = ['read'], ?Carbon $expiresAt = null, bool $isProtected = false): array
    {
        $secret = Str::random(48);
        $prefix = 'lk_'.Str::lower(Str::random(8));
        $plainText = $prefix.'.'.$secret;

        $token = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plainText),
            'prefix' => $prefix,
            'abilities' => $abilities,
            'is_protected' => $isProtected,
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'plainText' => $plainText];
    }

    /**
     * Resolve a usable token from a plaintext bearer value, or null if invalid,
     * revoked, or expired.
     */
    public static function findValid(string $plainText): ?ApiToken
    {
        $token = static::query()
            ->where('token_hash', hash('sha256', $plainText))
            ->first();

        if (! $token || $token->prefix === 'oauth' || $token->revoked_at !== null || ($token->user_id !== null && ! User::whereKey($token->user_id)->where('is_active', true)->where('is_admin', true)->exists())) {
            return null;
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return null;
        }

        return $token;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
