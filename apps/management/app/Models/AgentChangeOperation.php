<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentChangeOperation extends Model
{
    protected $fillable = ['change_set_id', 'sequence', 'entity_type', 'action', 'target_id', 'client_ref', 'parent_client_ref', 'expected_version', 'payload', 'sources', 'preview'];
    protected function casts(): array { return ['payload' => 'array', 'sources' => 'array', 'preview' => 'array']; }
    public function changeSet(): BelongsTo { return $this->belongsTo(AgentChangeSet::class, 'change_set_id'); }
}
