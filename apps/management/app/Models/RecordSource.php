<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordSource extends Model
{
    public $timestamps = false;
    protected $fillable = ['entity_type', 'entity_id', 'change_set_id', 'document_source_id', 'external_label', 'page', 'field_paths', 'verified', 'created_at'];
    protected function casts(): array { return ['field_paths' => 'array', 'verified' => 'boolean', 'created_at' => 'datetime']; }
}
