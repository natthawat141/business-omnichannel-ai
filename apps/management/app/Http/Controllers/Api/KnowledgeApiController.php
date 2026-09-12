<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\KnowledgeEntry;
use App\Models\ServicePackage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only knowledge API consumed by the Chatwoot AI service.
 *
 * Contract guarantees:
 *  - Only active records are ever returned.
 *  - Packages must additionally be published AND inside their effective window.
 * No inactive / unpublished / out-of-window record may leak under any filter.
 */
class KnowledgeApiController extends Controller
{
    private const SCHEMA_VERSION = '1.0';

    private const DEFAULT_LIMIT = 200;

    private const MAX_LIMIT = 500;

    /** Bounds the OR/relevance-score clause size for a single search request. */
    private const MAX_SEARCH_TERMS = 40;

    public function meta(Request $request): JsonResponse
    {
        $packages = ServicePackage::query()->publicOffer()->active()->published()->effective();

        $data = [
            'schema_version' => self::SCHEMA_VERSION,
            'counts' => [
                'packages' => (clone $packages)->count(),
                'faqs' => Faq::query()->active()->count(),
                'knowledge' => KnowledgeEntry::query()->active()->count(),
            ],
            'last_updated' => [
                'packages' => $this->latestUpdatedAt(clone $packages),
                'faqs' => $this->latestUpdatedAt(Faq::query()->active()),
                'knowledge' => $this->latestUpdatedAt(KnowledgeEntry::query()->active()),
            ],
        ];

        return $this->envelope($data, count($data['counts']));
    }

    public function packages(Request $request): JsonResponse
    {
        $query = ServicePackage::query()->publicOffer()->active()->published()->effective()
            ->with('category:id,name_th');

        $this->applyUpdatedSince($query, $request);

        if (($category = $request->query('category')) !== null && $category !== '') {
            $query->where('category_id', $category);
        }

        $rows = $query->orderBy('name_th')
            ->limit($this->limit($request))
            ->get()
            ->map(fn (ServicePackage $package) => [
                'id' => $package->id,
                'category_id' => $package->category_id,
                'category' => $package->category?->name_th,
                'code' => $package->code,
                'name_th' => $package->name_th,
                'name_en' => $package->name_en,
                'description_th' => $package->description_th,
                'price' => $package->price,
                'sale_price' => $package->sale_price,
                'currency' => $package->currency,
                'duration_minutes' => $package->duration_minutes,
                'keywords' => $package->keywords,
                'updated_at' => $this->iso($package->updated_at),
            ]);

        return $this->envelope($rows->all(), $rows->count());
    }

    public function faqs(Request $request): JsonResponse
    {
        $query = Faq::query()->active();

        $this->applyUpdatedSince($query, $request);
        $this->applySearch($query, $request, [
            'question_th', 'answer_th', 'question_en', 'answer_en', 'category', 'tags',
        ]);

        if (($category = $request->query('category')) !== null && $category !== '') {
            $query->where('category', $category);
        }

        $rows = $query->orderBy('id')
            ->limit($this->limit($request))
            ->get()
            ->map(fn (Faq $faq) => [
                'id' => $faq->id,
                'question_th' => $faq->question_th,
                'answer_th' => $faq->answer_th,
                'question_en' => $faq->question_en,
                'answer_en' => $faq->answer_en,
                'category' => $faq->category,
                'tags' => $faq->tags,
                'updated_at' => $this->iso($faq->updated_at),
            ]);

        return $this->envelope($rows->all(), $rows->count());
    }

    public function knowledge(Request $request): JsonResponse
    {
        $query = KnowledgeEntry::query()->active();

        $this->applyUpdatedSince($query, $request);
        $this->applySearch($query, $request, ['title', 'body', 'category', 'tags', 'type']);

        if (($type = $request->query('type')) !== null && $type !== '') {
            $query->where('type', $type);
        }

        if (($category = $request->query('category')) !== null && $category !== '') {
            $query->where('category', $category);
        }

        $rows = $query->orderBy('id')
            ->limit($this->limit($request))
            ->get()
            ->map(fn (KnowledgeEntry $entry) => [
                'id' => $entry->id,
                'title' => $entry->title,
                'body' => $entry->body,
                'type' => $entry->type,
                'category' => $entry->category,
                'tags' => $entry->tags,
                'source_url' => $entry->source_url,
                'version' => $entry->version,
                'reviewed_at' => $this->iso($entry->reviewed_at),
                'updated_at' => $this->iso($entry->updated_at),
            ]);

        return $this->envelope($rows->all(), $rows->count());
    }

    /**
     * Wrap a data payload in the stable compact envelope.
     */
    private function envelope(mixed $data, int $count): JsonResponse
    {
        return response()->json([
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'count' => $count,
                'version' => self::SCHEMA_VERSION,
            ],
            'data' => $data,
        ]);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyUpdatedSince(Builder $query, Request $request): void
    {
        $since = $request->query('updated_since');

        if ($since === null || $since === '') {
            return;
        }

        try {
            $timestamp = \Illuminate\Support\Carbon::parse($since);
        } catch (\Throwable) {
            return;
        }

        $query->where('updated_at', '>=', $timestamp);
    }

    /**
     * Apply a bounded, escaped multi-term search for the AI service.
     *
     * Thai script rarely has spaces between words, so a single whole-string
     * LIKE only matches a near-identical phrase and misses close questions
     * (e.g. "ค่าโอนใครจ่าย" against a FAQ titled "ค่าธรรมเนียมการโอนกรรมสิทธิ์").
     * `searchTerms()` breaks the query into the whole phrase, whitespace
     * tokens, and 2-character bigrams and scores each row by how many of
     * those terms it matches. A single short bigram (e.g. "เง") is common
     * enough in unrelated Thai text to match by coincidence, so a floor of
     * roughly half the terms is required -- this is what keeps the original
     * "no fallback to unrelated rows" contract: a genuinely close rephrasing
     * shares most of its bigrams with the target text, a coincidental
     * one-syllable overlap does not.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     */
    private function applySearch(Builder $query, Request $request, array $columns): void
    {
        $search = mb_substr(trim((string) $request->query('q')), 0, 160);

        if ($search === '') {
            return;
        }

        $terms = $this->searchTerms($search);

        if ($terms === []) {
            return;
        }

        [$scoreSql, $bindings] = $this->relevanceScore($terms, $columns);
        $threshold = max(2, (int) ceil(count($terms) / 2));

        $query->whereRaw("({$scoreSql}) >= ?", [...$bindings, $threshold]);
        $query->orderByRaw("({$scoreSql}) DESC", $bindings);
    }

    /**
     * @return array<int, string>
     */
    private function searchTerms(string $search): array
    {
        $terms = [$search];

        foreach (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= 2) {
                $terms[] = $word;
            }
        }

        $chars = preg_split('//u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        for ($i = 0; $i < count($chars) - 1; $i++) {
            if ($chars[$i] === ' ' || $chars[$i + 1] === ' ') {
                continue;
            }
            $terms[] = $chars[$i].$chars[$i + 1];
        }

        return array_slice(array_values(array_unique($terms)), 0, self::MAX_SEARCH_TERMS);
    }

    /**
     * Ranks rows by how many search terms they matched, so a near-exact
     * phrase match (or a row matching many bigrams) outranks a row that only
     * happens to contain one common two-character sequence.
     *
     * $columns is always one of the controller's own hardcoded allowlists,
     * never request input, so interpolating it into raw SQL here is safe.
     *
     * @param  array<int, string>  $terms
     * @param  array<int, string>  $columns
     * @return array{0: string, 1: array<int, string>}
     */
    private function relevanceScore(array $terms, array $columns): array
    {
        $expressions = [];
        $bindings = [];

        foreach ($terms as $term) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            foreach ($columns as $column) {
                $expressions[] = "(CASE WHEN {$column} LIKE ? THEN 1 ELSE 0 END)";
                $bindings[] = $like;
            }
        }

        return [implode(' + ', $expressions), $bindings];
    }

    private function limit(Request $request): int
    {
        $limit = (int) $request->query('limit', (string) self::DEFAULT_LIMIT);

        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function latestUpdatedAt(Builder $query): ?string
    {
        $value = $query->max('updated_at');

        if ($value === null) {
            return null;
        }

        return $this->iso(\Illuminate\Support\Carbon::parse($value));
    }

    private function iso(?\DateTimeInterface $value): ?string
    {
        return $value !== null ? \Illuminate\Support\Carbon::instance($value)->toIso8601String() : null;
    }
}
