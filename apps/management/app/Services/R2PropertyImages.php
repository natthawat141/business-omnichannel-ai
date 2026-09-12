<?php

namespace App\Services;

use App\Exceptions\PropertyImageUploadUnavailable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class R2PropertyImages
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @return array{id: string, upload_url: string, delivery_url: string, expires_at: string, method: string, headers: array<string, string>}
     */
    public function createDirectUpload(string $contentType): array
    {
        $extension = self::EXTENSIONS[$contentType] ?? null;
        $disk = config('filesystems.disks.r2', []);
        $publicBaseUrl = rtrim(trim((string) ($disk['url'] ?? '')), '/');
        $endpoint = rtrim(trim((string) ($disk['endpoint'] ?? '')), '/');
        $bucket = trim((string) ($disk['bucket'] ?? ''));
        $expiryMinutes = (int) config('services.cloudflare_r2.direct_upload_expiry_minutes', 10);

        if ($extension === null
            || trim((string) ($disk['key'] ?? '')) === ''
            || trim((string) ($disk['secret'] ?? '')) === ''
            || ! preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket)
            || ! $this->isHttpsUrl($endpoint)
            || ! $this->isHttpsUrl($publicBaseUrl)
            || $expiryMinutes < 2
            || $expiryMinutes > 60) {
            throw new PropertyImageUploadUnavailable('Cloudflare R2 is not configured.');
        }

        $id = (string) Str::uuid();
        $path = "properties/{$id}.{$extension}";
        $expiresAt = now('UTC')->addMinutes($expiryMinutes);

        try {
            $upload = Storage::disk('r2')->temporaryUploadUrl($path, $expiresAt, [
                'ContentType' => $contentType,
            ]);
        } catch (Throwable $exception) {
            throw new PropertyImageUploadUnavailable('Cloudflare R2 is unavailable.', previous: $exception);
        }

        $uploadUrl = $upload['url'] ?? null;
        $headers = $upload['headers'] ?? null;

        if (! is_string($uploadUrl)
            || ! $this->isHttpsUrl($uploadUrl)
            || ! is_array($headers)) {
            throw new PropertyImageUploadUnavailable('Cloudflare R2 returned an invalid upload response.');
        }

        return [
            'id' => $id,
            'upload_url' => $uploadUrl,
            'delivery_url' => "{$publicBaseUrl}/{$path}",
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            'method' => 'PUT',
            'headers' => collect($headers)
                ->filter(function ($value, $name): bool {
                    $normalizedName = strtolower((string) $name);

                    return $normalizedName === 'content-type'
                        || str_starts_with($normalizedName, 'x-amz-');
                })
                ->map(fn ($value): string => is_array($value) ? implode(', ', $value) : (string) $value)
                ->all(),
        ];
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
