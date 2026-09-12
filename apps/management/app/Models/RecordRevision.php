<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordRevision extends Model
{
    public $timestamps = false;
    protected $fillable = ['entity_type', 'entity_id', 'change_set_id', 'api_token_id', 'user_id', 'action', 'lock_version', 'before', 'after', 'created_at'];
    protected function casts(): array { return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime']; }
}
