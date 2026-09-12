<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Faq extends Model
{
    /** @use HasFactory<\Database\Factories\FaqFactory> */
    use HasFactory;

    protected $attributes = ['lock_version' => 1];

    protected $fillable = [
        'question_th',
        'answer_th',
        'question_en',
        'answer_en',
        'category',
        'tags',
        'is_active',
        'lock_version',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lock_version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Faq>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('archived_at');
    }

    /** @param Builder<Faq> $query */
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
