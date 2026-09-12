<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DocumentApiResource;
use App\Models\DocumentSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Read-only Document Intake API consumed by authorized external agent clients (CLI, MCP).
 *
 * Exposes only safe allowlisted metadata. Cancelled documents are strictly 404'd/excluded.
 * Never exposes private storage paths, file hashes, owner identity, or file download endpoints.
 */
class DocumentApiController extends Controller
{
    private const SCHEMA_VERSION = '1.0';

    private const DEFAULT_LIMIT = 15;

    private const MAX_LIMIT = 50;

    private const MAX_PAGE = 1000;

    /** @var array<int, string> */
    private const ALLOWED_STATUSES = [
        DocumentSource::STATUS_UPLOADED,
        DocumentSource::STATUS_EXTRACTING,
        DocumentSource::STATUS_READY,
        DocumentSource::STATUS_OCR_REQUIRED,
        DocumentSource::STATUS_FAILED,
    ];

    /**
     * List document intake sources with bounded pagination and status filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid document query parameters.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $status = $request->query('status');
        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);
        $page = (int) $request->query('page', 1);

        $query = DocumentSource::query()
            ->where('status', '!=', DocumentSource::STATUS_CANCELLED);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $paginator = $query->latest('id')->paginate(
            perPage: $limit,
            columns: ['*'],
            pageName: 'page',
            page: $page
        );

        return response()->json([
            'meta' => [
                'version' => self::SCHEMA_VERSION,
                'count' => $paginator->count(),
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'applied_filters' => [
                    'status' => $status,
                ],
            ],
            'data' => DocumentApiResource::collection($paginator->items()),
        ]);
    }

    /**
     * Retrieve safe allowlisted metadata for a single document intake source.
     */
    public function show(mixed $document): JsonResponse
    {
        if (! is_numeric($document) || (int) $document <= 0) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $source = DocumentSource::query()
            ->where('id', (int) $document)
            ->where('status', '!=', DocumentSource::STATUS_CANCELLED)
            ->first();

        if (! $source) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return response()->json([
            'meta' => [
                'version' => self::SCHEMA_VERSION,
            ],
            'data' => new DocumentApiResource($source),
        ]);
    }
}
