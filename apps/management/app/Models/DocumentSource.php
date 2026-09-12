<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSource extends Model
{
    use HasFactory;

    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_EXTRACTING = 'extracting';
    public const STATUS_READY = 'ready';
    public const STATUS_OCR_REQUIRED = 'ocr_required';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_TYPE_UPLOAD = 'upload';
    public const SOURCE_TYPE_GOOGLE_DRIVE = 'google_drive';

    public const FAILURE_INVALID_PDF = 'invalid_pdf';
    public const FAILURE_EMPTY_FILE = 'empty_file';
    public const FAILURE_OVERSIZED = 'oversized';
    public const FAILURE_SECURITY_REJECTED = 'security_rejected';
    public const FAILURE_PROCESSING_ERROR = 'processing_error';

    protected $fillable = [
        'user_id',
        'source_type',
        'original_filename',
        'storage_disk',
        'storage_path',
        'file_hash',
        'mime_type',
        'file_size',
        'page_count',
        'status',
        'failure_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'page_count' => 'integer',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
