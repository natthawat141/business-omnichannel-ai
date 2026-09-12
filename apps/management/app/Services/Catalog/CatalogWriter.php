<?php

namespace App\Services\Catalog;

use App\Models\ServicePackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Server-side optimistic write primitive for the admin form and change engine. */
class CatalogWriter
{
    /** @param array<string, mixed> $attributes */
    public function update(ServicePackage $package, array $attributes, int $expectedVersion): ServicePackage
    {
        return DB::transaction(function () use ($package, $attributes, $expectedVersion) {
            $current = ServicePackage::query()->lockForUpdate()->findOrFail($package->id);
            if ($current->archived_at !== null) {
                throw ValidationException::withMessages(['package' => 'Archived records must be restored before editing.']);
            }
            if ($current->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['lock_version' => 'This record changed. Reload it before saving.']);
            }
            unset($attributes['lock_version'], $attributes['archived_at']);
            $current->fill($attributes);
            $current->lock_version++;
            $current->save();

            return $current;
        });
    }
}
