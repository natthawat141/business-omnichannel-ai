<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentChangeSet extends Model
{
    use HasUuids;

    public const PROPOSED = 'proposed';
    public const APPROVED = 'approved';
    public const APPLIED = 'applied';
    public const REJECTED = 'rejected';
    public const CONFLICTED = 'conflicted';
    public const SUSPENDED = 'suspended';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'api_token_id', 'submitted_by_user_id', 'reviewed_by_user_id', 'applied_by_user_id',
        'status', 'idempotency_key', 'payload_hash', 'schema_versions', 'operation_count',
        'review_note', 'reviewed_at', 'applied_at', 'suspended_at',
    ];

    protected function casts(): array
    {
        return ['schema_versions' => 'array', 'reviewed_at' => 'datetime', 'applied_at' => 'datetime', 'suspended_at' => 'datetime'];
    }

    public function token(): BelongsTo { return $this->belongsTo(ApiToken::class, 'api_token_id'); }
    public function operations(): HasMany { return $this->hasMany(AgentChangeOperation::class, 'change_set_id')->orderBy('sequence'); }
}
