<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpConnection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'session_version' => 'integer', 'user_id' => 'integer', 'api_token_id' => 'integer',
            'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }
}
