<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Bounded catalog retrieval for the AI service. All filters are explicitly
 * allowlisted here; request values never become column names or SQL fragments.
 */
class CatalogSearchController extends Controller
{
    private const MAX_LIMIT = 20;

    /** @var array<string, string> */
    private const ATTRIBUTE_COLUMNS = [
        'bedrooms' => 'bedrooms',
        'bathrooms' => 'bathrooms',
        'usable_area_sqm' => 'usable_area_sqm',
        'land_area_sqw' => 'land_area_sqw',
        'floor' => 'floor',
    ];

    public function search(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (! is_array($input)) {
            return response()->json(['message' => 'The request body must be an object.'], 422);
        }

        $validator = Validator::make($input, [
            'query' => ['nullable', 'string', 'max:160'],
            'category_slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/'],
            'transaction_type' => ['nullable', Rule::in(['sale', 'rent', 'service'])],
            'availability' => ['nullable', 'array', 'max:3'],
            'availability.*' => [Rule::in(['available', 'reserved', 'unavailable'])],
            'location' => ['nullable', 'array:province,district,subdistrict,text'],
            'location.province' => ['nullable', 'string', 'max:100'],
            'location.district' => ['nullable', 'string', 'max:100'],
            'location.subdistrict' => ['nullable', 'string', 'max:100'],
            'location.text' => ['nullable', 'string', 'max:160'],
            'price' => ['nullable', 'array:min,max'],
            'price.min' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'price.max' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'attributes' => ['nullable', 'array', 'max:5'],
            'sort' => ['nullable', Rule::in(['relevance', 'price_asc', 'price_desc', 'updated_desc'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:64'],
        ]);

        // `price.max` is a valid standalone filter (the common "ไม่เกิน ..."
        // query). Only enforce the range relationship when both bounds exist.
        $validator->after(function ($validator) use ($input): void {
            $price = $input['price'] ?? null;
            if (! is_array($price) || ! array_key_exists('min', $price) || ! array_key_exists('max', $price)
                || ! is_numeric($price['min']) || ! is_numeric($price['max'])) {
                return;
            }

            if ((float) $price['max'] < (float) $price['min']) {
                $validator->errors()->add('price.max', 'The price.max field must be greater than or equal to price.min.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid catalog search.', 'errors' => $validator->errors()], 422);
        }

        $filters = $validator->validated();
        $attributeFilters = $filters['attributes'] ?? [];
        foreach ($attributeFilters as $attribute => $condition) {
            if (! array_key_exists($attribute, self::ATTRIBUTE_COLUMNS) || ! is_array($condition)
                || count($condition) !== 1 || ! in_array(array_key_first($condition), ['eq', 'gte', 'lte'], true)
                || ! is_numeric(reset($condition))) {
                return response()->json(['message' => 'Invalid attribute filter.'], 422);
            }
        }

        $query = ServicePackage::query()
            ->publicOffer()->active()->published()->effective()
            ->with('category:id,name_th,slug')
            ->where('availability', 'available');

        $this->applyFilters($query, $filters, $attributeFilters);
        $sort = $filters['sort'] ?? 'relevance';
        $this->applySort($query, $sort);

        $limit = $filters['limit'] ?? 10;
        $offset = $this->cursorOffset($filters['cursor'] ?? null);
        if ($offset === null) {
            return response()->json(['message' => 'Invalid cursor.'], 422);
        }
        $rows = $query->offset($offset)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (ServicePackage $item) => $this->summary($item))->values();

        return response()->json([
            'meta' => [
                'version' => '1.1',
                'count' => $items->count(),
                'applied_filters' => $this->safeFilters($filters),
                'next_cursor' => $hasMore ? $this->cursor($offset + $items->count()) : null,
            ],
            'data' => $items,
        ]);
    }

    public function show(ServicePackage $package): JsonResponse
    {
        abort_unless($package->record_kind === 'offer' && $package->archived_at === null && $package->is_active && $package->is_published && $package->availability === 'available'
            && ($package->effective_from === null || $package->effective_from->lte(today()))
            && ($package->effective_until === null || $package->effective_until->gte(today())), 404);

        $package->load('category:id,name_th,slug');

        return response()->json(['meta' => ['version' => '1.1'], 'data' => $this->summary($package, true)]);
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $attributeFilters */
    private function applyFilters(Builder $query, array $filters, array $attributeFilters): void
    {
        if (! empty($filters['category_slug'])) {
            $query->whereHas('category', fn (Builder $category) => $category->where('slug', $filters['category_slug']));
        }
        if (! empty($filters['transaction_type'])) {
            $query->where('transaction_type', $filters['transaction_type']);
        }
        foreach (($filters['location'] ?? []) as $field => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $column = $field === 'text' ? 'location_text' : $field;
            $query->where($column, 'like', '%'.$this->escapeLike($value).'%');
        }
        if (! empty($filters['query'])) {
            $term = '%'.$this->escapeLike((string) $filters['query']).'%';
            $query->where(fn (Builder $nested) => $nested->where('name_th', 'like', $term)
                ->orWhere('name_en', 'like', $term)->orWhere('code', 'like', $term)
                ->orWhere('description_th', 'like', $term)->orWhere('keywords', 'like', $term)
                ->orWhere('location_text', 'like', $term)->orWhere('project_name', 'like', $term));
        }
        if (isset($filters['price']['min'])) {
            $query->whereRaw('COALESCE(sale_price, price) >= ?', [$filters['price']['min']]);
        }
        if (isset($filters['price']['max'])) {
            $query->whereRaw('COALESCE(sale_price, price) <= ?', [$filters['price']['max']]);
        }
        foreach ($attributeFilters as $attribute => $condition) {
            $operator = ['eq' => '=', 'gte' => '>=', 'lte' => '<='][array_key_first($condition)];
            $query->where(self::ATTRIBUTE_COLUMNS[$attribute], $operator, reset($condition));
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderByRaw('COALESCE(sale_price, price) asc')->orderBy('id'),
            'price_desc' => $query->orderByRaw('COALESCE(sale_price, price) desc')->orderBy('id'),
            'updated_desc' => $query->orderByDesc('updated_at')->orderBy('id'),
            default => $query->orderBy('name_th')->orderBy('id'),
        };
    }

    /** @return array<string, mixed> */
    private function summary(ServicePackage $item, bool $detail = false): array
    {
        $data = [
            'id' => $item->id, 'code' => $item->code, 'item_type' => $item->item_type,
            'category' => $item->category?->name_th, 'category_slug' => $item->category?->slug,
            'name_th' => $item->name_th, 'name_en' => $item->name_en,
            'transaction_type' => $item->transaction_type, 'availability' => $item->availability,
            'price' => $item->price !== null ? (float) $item->price : null,
            'sale_price' => $item->sale_price !== null ? (float) $item->sale_price : null,
            'currency' => $item->currency, 'location_text' => $item->location_text,
            'province' => $item->province, 'district' => $item->district, 'subdistrict' => $item->subdistrict,
            'project_name' => $item->project_name, 'bedrooms' => $item->bedrooms, 'bathrooms' => $item->bathrooms,
            'primary_image_url' => $item->primary_image_url,
            'map_url' => $item->map_url,
            'usable_area_sqm' => $item->usable_area_sqm !== null ? (float) $item->usable_area_sqm : null,
            'land_area_sqw' => $item->land_area_sqw !== null ? (float) $item->land_area_sqw : null,
            'floor' => $item->floor, 'updated_at' => $item->updated_at?->toIso8601String(),
        ];
        if ($detail) {
            $data += ['description_th' => $item->description_th, 'description_en' => $item->description_en,
                'terms' => $item->terms, 'keywords' => $item->keywords, 'attributes' => $item->attributes];
        }
        return $data;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function safeFilters(array $filters): array
    {
        unset($filters['cursor']);
        return $filters;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function cursor(int $offset): string { return rtrim(strtr(base64_encode('v1:'.$offset), '+/', '-_'), '='); }
    private function cursorOffset(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        return is_string($raw) && preg_match('/^v1:([0-9]+)$/', $raw, $m) === 1 && (int) $m[1] <= 10000 ? (int) $m[1] : null;
    }
}
