<?php

namespace App\Services;

use App\Exceptions\PropertyImageUploadUnavailable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SupabaseStorageImages
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
        $disk = config('filesystems.disks.supabase', []);
        $supabaseUrl = rtrim(trim((string) config('services.supabase.url')), '/');
        $endpoint = rtrim(trim((string) ($disk['endpoint'] ?? '')), '/');
        $bucket = trim((string) ($disk['bucket'] ?? ''));
        $expiryMinutes = (int) config('services.supabase_storage.direct_upload_expiry_minutes', 10);

        $projectHost = strtolower((string) parse_url($supabaseUrl, PHP_URL_HOST));
        $endpointHost = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $projectRef = preg_replace('/\.supabase\.co$/', '', $projectHost);
        $expectedEndpointHost = is_string($projectRef) ? "{$projectRef}.storage.supabase.co" : '';
        $endpointPath = (string) parse_url($endpoint, PHP_URL_PATH);

        if ($extension === null
            || trim((string) ($disk['key'] ?? '')) === ''
            || trim((string) ($disk['secret'] ?? '')) === ''
            || ! preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket)
            || ! $this->isHttpsUrl($supabaseUrl)
            || ! preg_match('/^[a-z0-9-]+\.supabase\.co$/', $projectHost)
            || ! $this->isHttpsUrl($endpoint)
            || $endpointHost !== $expectedEndpointHost
            || $endpointPath !== '/storage/v1/s3'
            || trim((string) ($disk['region'] ?? '')) === ''
            || $expiryMinutes < 2
            || $expiryMinutes > 60) {
            throw new PropertyImageUploadUnavailable('Supabase Storage is not configured.');
        }

        $id = (string) Str::uuid();
        $path = "properties/{$id}.{$extension}";
        $expiresAt = now('UTC')->addMinutes($expiryMinutes);

        try {
            $upload = Storage::disk('supabase')->temporaryUploadUrl($path, $expiresAt, [
                'ContentType' => $contentType,
            ]);
        } catch (Throwable $exception) {
            throw new PropertyImageUploadUnavailable('Supabase Storage is unavailable.', previous: $exception);
        }

        $uploadUrl = $upload['url'] ?? null;
        $headers = $upload['headers'] ?? null;
        $expectedPath = "{$endpointPath}/{$bucket}/{$path}";
        $clientHeaders = is_array($headers)
            ? collect($headers)->filter(function ($value, $name): bool {
                $normalizedName = strtolower((string) $name);

                return $normalizedName === 'content-type'
                    || str_starts_with($normalizedName, 'x-amz-');
            })
            : collect();
        $signedContentType = $clientHeaders->first(fn ($value, $name): bool => strtolower((string) $name) === 'content-type');
        if (is_array($signedContentType)) {
            $signedContentType = implode(', ', $signedContentType);
        }

        if (! is_string($uploadUrl)
            || ! $this->isHttpsUrl($uploadUrl)
            || strtolower((string) parse_url($uploadUrl, PHP_URL_HOST)) !== $endpointHost
            || parse_url($uploadUrl, PHP_URL_PATH) !== $expectedPath
            || ! is_string($signedContentType)
            || strtolower($signedContentType) !== strtolower($contentType)) {
            throw new PropertyImageUploadUnavailable('Supabase Storage returned an invalid upload response.');
        }

        return [
            'id' => $id,
            'upload_url' => $uploadUrl,
            'delivery_url' => "{$supabaseUrl}/storage/v1/object/public/{$bucket}/{$path}",
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            'method' => 'PUT',
            'headers' => $clientHeaders
                ->map(fn ($value): string => is_array($value) ? implode(', ', $value) : (string) $value)
                ->all(),
        ];
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
    }
}
