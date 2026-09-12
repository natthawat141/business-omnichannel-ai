<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PackageCategory extends Model
{
    /** @use HasFactory<\Database\Factories\PackageCategoryFactory> */
    use HasFactory;

    protected $attributes = ['schema_version' => 1];

    protected $fillable = [
        'name_th',
        'name_en',
        'slug',
        'description',
        'attribute_definitions',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'attribute_definitions' => 'array',
            'schema_version' => 'integer',
        ];
    }

    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            if ($this->exists) {
                $current = static::query()->lockForUpdate()->findOrFail($this->getKey());
                if ($this->isDirty('attribute_definitions') && $current->schema_version !== $this->getOriginal('schema_version')) {
                    throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Category schema changed; reload before editing definitions.');
                }
                // A stale name-only edit must not restore an earlier schema version.
                $this->schema_version = $current->schema_version;
            }
            return parent::save($options);
        });
    }

    public function delete()
    {
        return $this->getConnection()->transaction(function () {
            if ($this->exists) {
                $current = static::query()->lockForUpdate()->findOrFail($this->getKey());
                $this->schema_version = $current->schema_version;
            }
            return parent::delete();
        });
    }

    protected static function booted(): void
    {
        // Auto-fill slug from the Thai (or English) name when left blank.
        static::saving(function (PackageCategory $category) {
            $legacy = $category->schema_version === 1
                && app(\App\Services\Catalog\AttributeValidator::class)->legacyCoreDefinitions($category->attribute_definitions);
            if ($category->isDirty('attribute_definitions') && ! $legacy) {
                $validator = app(\App\Services\Catalog\AttributeValidator::class);
                $definitions = $category->attribute_definitions ?? [];
                $validator->definitions($definitions);
                if ($category->exists) {
                    if ($category->schema_version === 1) {
                        foreach ($category->packages()->whereNotNull('attributes')->cursor() as $item) {
                            if ($item->getAttribute('attributes') !== []) {
                                throw \Illuminate\Validation\ValidationException::withMessages([
                                    'attribute_definitions' => 'Legacy attributes need a separately reviewed conversion before typed activation.',
                                ]);
                            }
                        }
                    } elseif ($category->packages()->exists()) {
                        $new = array_column($definitions, null, 'key');
                        foreach ($category->getOriginal('attribute_definitions') ?? [] as $old) {
                            if (! isset($new[$old['key']]) || $new[$old['key']] != $old) {
                                throw \Illuminate\Validation\ValidationException::withMessages([
                                    'attribute_definitions' => 'Existing definitions are protected while records exist; only optional additions are allowed.',
                                ]);
                            }
                        }
                    }
                }
                $category->schema_version = max(2, ($category->exists ? $category->schema_version : 1) + 1);
            }
            if (blank($category->slug)) {
                $base = Str::slug($category->name_en ?: $category->name_th);
                $category->slug = $base ?: 'category-'.uniqid();
            }
        });
        static::deleting(function (PackageCategory $category) {
            if ($category->schema_version >= 2 && $category->packages()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'category' => 'Move records through a reviewed conversion before removing their typed schema.',
                ]);
            }
        });
    }

    /**
     * @return HasMany<ServicePackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(ServicePackage::class, 'category_id');
    }

    /**
     * @param  Builder<PackageCategory>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
