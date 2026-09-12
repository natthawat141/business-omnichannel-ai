<?php

namespace App\Models;

use App\Support\PublicImageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ServicePackage extends Model
{
    /** @use HasFactory<\Database\Factories\ServicePackageFactory> */
    use HasFactory;

    protected $table = 'packages';

    protected $attributes = [
        'record_kind' => 'offer',
        'lock_version' => 1,
    ];

    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            // Serialize category activation with writes, including import/model paths.
            // Catalog record optimistic locking itself is the next implementation slice.
            $categoryIds = array_filter([$this->getOriginal('category_id'), $this->category_id]);
            if ($categoryIds !== []) {
                PackageCategory::query()->whereIn('id', array_unique($categoryIds))->orderBy('id')->lockForUpdate()->get();
            }
            return parent::save($options);
        });
    }

    protected static function booted(): void
    {
        static::saving(function (ServicePackage $package) {
            if ($package->isDirty('map_url')) {
                validator(['map_url' => $package->map_url], [
                    'map_url' => ['nullable', 'url:https', 'max:2048'],
                ])->validate();
            }
            if (! $package->exists && $package->lock_version === null) {
                $package->lock_version = 1;
            }
            if ($package->isDirty(['attributes', 'category_id'])) {
                app(\App\Services\Catalog\AttributeValidator::class)->values(
                    $package->getAttribute('attributes'),
                    $package->category_id ? PackageCategory::findOrFail($package->category_id) : null,
                );
            }
            if ($package->profile !== null && $package->availability === null) {
                $package->availability = 'unknown';
            }
            app(\App\Services\Catalog\CatalogProfiles::class)->validate($package->toArray());
            $package->validateHierarchy();
        });
    }

    public function setPrimaryImageUrlAttribute(?string $value): void
    {
        $this->attributes['primary_image_url'] = PublicImageUrl::normalize($value);
    }

    protected $fillable = [
        'category_id',
        'item_type',
        'record_kind',
        'parent_id',
        'code',
        'name_th',
        'name_en',
        'description_th',
        'description_en',
        'price',
        'sale_price',
        'currency',
        'transaction_type',
        'availability',
        'duration_minutes',
        'terms',
        'keywords',
        'location_text',
        'province',
        'district',
        'subdistrict',
        'project_name',
        'primary_image_url',
        'map_url',
        'bedrooms',
        'bathrooms',
        'usable_area_sqm',
        'land_area_sqw',
        'floor',
        'attributes',
        'profile',
        'profile_data',
        'lock_version',
        'archived_at',
        'is_active',
        'is_published',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'floor' => 'integer',
            'usable_area_sqm' => 'decimal:2',
            'land_area_sqw' => 'decimal:2',
            'attributes' => 'array',
            'profile_data' => 'array',
            'lock_version' => 'integer',
            'archived_at' => 'datetime',
            'is_active' => 'boolean',
            'is_published' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PackageCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PackageCategory::class, 'category_id');
    }

    /** @return BelongsTo<ServicePackage, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<ServicePackage, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Recoverable archive; an active hierarchy is never cascaded. */
    public function archive(): void
    {
        DB::transaction(function () {
            $current = self::query()->lockForUpdate()->findOrFail($this->id);
            if ($current->archived_at !== null) {
                return;
            }
            if ($current->children()->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['package' => 'Archive child records first; no cascade is performed.']);
            }
            $current->archived_at = now();
            $current->lock_version++;
            $current->save();
            $this->setRawAttributes($current->getAttributes(), true);
        });
    }

    public function restoreFromArchive(): void
    {
        DB::transaction(function () {
            $current = self::query()->lockForUpdate()->findOrFail($this->id);
            if ($current->archived_at === null) {
                return;
            }
            if ($current->code !== null && self::query()->where('code', $current->code)->whereNull('archived_at')->whereKeyNot($current->id)->exists()) {
                throw ValidationException::withMessages(['code' => 'A live record already uses this code.']);
            }
            $current->archived_at = null;
            $current->lock_version++;
            $current->save();
            $this->setRawAttributes($current->getAttributes(), true);
        });
    }

    public function validateHierarchy(): void
    {
        $kind = $this->record_kind ?? 'offer';
        if (! in_array($kind, ['group', 'variant', 'offer'], true)) {
            throw ValidationException::withMessages(['record_kind' => 'Record kind must be group, variant or offer.']);
        }
        if ($this->parent_id === null) {
            return;
        }
        if ($this->exists && (int) $this->parent_id === (int) $this->id) {
            throw ValidationException::withMessages(['parent_id' => 'A record cannot be its own parent.']);
        }
        $parent = self::query()->find($this->parent_id);
        if ($parent === null || $parent->archived_at !== null) {
            throw ValidationException::withMessages(['parent_id' => 'Parent must be an existing non-archived record.']);
        }
        $expectedParentKinds = match ($kind) {
            'variant' => ['group'],
            'offer' => ['group', 'variant'],
            default => [],
        };
        if (! in_array($parent->record_kind, $expectedParentKinds, true)) {
            throw ValidationException::withMessages(['parent_id' => 'Variants belong to groups; offers belong to groups or variants.']);
        }
        $cursor = $parent;
        for ($depth = 0; $depth < 3 && $cursor->parent_id !== null; $depth++) {
            if ($this->exists && (int) $cursor->parent_id === (int) $this->id) {
                throw ValidationException::withMessages(['parent_id' => 'Catalog hierarchy cannot contain a cycle.']);
            }
            $cursor = self::query()->find($cursor->parent_id);
            if ($cursor === null) {
                break;
            }
        }
    }

    /**
     * @param  Builder<ServicePackage>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<ServicePackage>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Only packages inside their effective date window (per the shared product contract).
     *
     * @param  Builder<ServicePackage>  $query
     */
    public function scopeEffective(Builder $query, ?Carbon $on = null): void
    {
        $date = ($on ?? now())->toDateString();

        $query->where(function (Builder $q) use ($date) {
            $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date);
        })->where(function (Builder $q) use ($date) {
            $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
        });
    }

    /** @param Builder<ServicePackage> $query */
    public function scopeUnarchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<ServicePackage> $query */
    public function scopePublicOffer(Builder $query): void
    {
        $query->unarchived()->where('record_kind', 'offer');
    }
}
