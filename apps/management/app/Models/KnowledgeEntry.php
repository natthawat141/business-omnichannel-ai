<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class KnowledgeEntry extends Model
{
    /** @use HasFactory<\Database\Factories\KnowledgeEntryFactory> */
    use HasFactory;

    protected $attributes = ['lock_version' => 1];

    protected $fillable = [
        'title',
        'body',
        'type',
        'category',
        'tags',
        'source_url',
        'version',
        'is_active',
        'reviewed_at',
        'lock_version',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
            'reviewed_at' => 'datetime',
            'lock_version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<KnowledgeEntry>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('archived_at');
    }

    /** @param Builder<KnowledgeEntry> $query */
    public function scopeUnarchived(Builder $query): void { $query->whereNull('archived_at'); }

    public function archive(): void
    {
        DB::transaction(function () {
            $current = self::query()->lockForUpdate()->findOrFail($this->id);
            if ($current->archived_at === null) {
                $current->archived_at = now();
                $current->lock_version++;
                $current->save();
            }
            $this->setRawAttributes($current->getAttributes(), true);
        });
    }

    public function restoreFromArchive(): void
    {
        DB::transaction(function () {
            $current = self::query()->lockForUpdate()->findOrFail($this->id);
            if ($current->archived_at !== null) {
                $current->archived_at = null;
                $current->lock_version++;
                $current->save();
            }
            $this->setRawAttributes($current->getAttributes(), true);
        });
    }
}
