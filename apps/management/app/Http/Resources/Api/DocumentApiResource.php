<?php

namespace App\Http\Resources\Api;

use App\Models\DocumentSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentSource
 */
class DocumentApiResource extends JsonResource
{
    /** @var array<int, string> */
    public const ALLOWED_FAILURE_CATEGORIES = [
        DocumentSource::FAILURE_INVALID_PDF,
        DocumentSource::FAILURE_EMPTY_FILE,
        DocumentSource::FAILURE_OVERSIZED,
        DocumentSource::FAILURE_SECURITY_REJECTED,
        DocumentSource::FAILURE_PROCESSING_ERROR,
    ];

    /**
     * Transform the resource into a safe allowlisted metadata array.
     *
     * Exposes exactly one failure_category (never raw failure_reason text).
     * Explicitly excludes private storage path/disk, SHA-256 hash, full metadata blobs,
     * owner identity/user, raw file bytes/text, exception detail, auth tokens, and public URLs.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'source_type' => (string) $this->source_type,
            'original_filename' => (string) $this->original_filename,
            'mime_type' => (string) $this->mime_type,
            'file_size' => (int) $this->file_size,
            'status' => (string) $this->status,
            'page_count' => $this->page_count !== null ? (int) $this->page_count : null,
            'failure_category' => $this->safeFailureCategory(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Map fixed failure enums to the safe category, collapsing any unknown or raw text to processing_error.
     */
    private function safeFailureCategory(): ?string
    {
        if ($this->failure_reason === null || $this->failure_reason === '') {
            return null;
        }

        if (in_array($this->failure_reason, self::ALLOWED_FAILURE_CATEGORIES, true)) {
            return $this->failure_reason;
        }

        return DocumentSource::FAILURE_PROCESSING_ERROR;
    }
}
