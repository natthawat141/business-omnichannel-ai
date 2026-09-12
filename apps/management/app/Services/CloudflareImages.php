<?php

namespace App\Services;

use App\Exceptions\CloudflareImagesUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CloudflareImages
{
    /**
     * @return array{id: string, upload_url: string, delivery_url: string, expires_at: string, method: string, headers: array<string, string>}
     */
    public function createDirectUpload(): array
    {
        $accountId = trim((string) config('services.cloudflare_images.account_id'));
        $apiToken = trim((string) config('services.cloudflare_images.api_token'));
        $deliveryBaseUrl = rtrim(trim((string) config('services.cloudflare_images.delivery_base_url')), '/');
        $variant = trim((string) config('services.cloudflare_images.variant', 'public'));
        $expiryMinutes = (int) config('services.cloudflare_images.direct_upload_expiry_minutes', 10);

        if (! preg_match('/^[a-f0-9]{32}$/i', $accountId)
            || $apiToken === ''
            || ! $this->isHttpsUrl($deliveryBaseUrl)
            || ! preg_match('/^[A-Za-z0-9_-]+$/', $variant)
            || $expiryMinutes < 2
            || $expiryMinutes > 360) {
            throw new CloudflareImagesUnavailable('Cloudflare Images is not configured.');
        }

        $expiresAt = now('UTC')->addMinutes($expiryMinutes)->format('Y-m-d\TH:i:s\Z');

        try {
            $response = Http::acceptJson()
                ->withToken($apiToken)
                ->asMultipart()
                ->connectTimeout(5)
                ->timeout(10)
                ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/images/v2/direct_upload", [
                    'requireSignedURLs' => 'false',
                    'expiry' => $expiresAt,
                ]);
        } catch (ConnectionException $exception) {
            throw new CloudflareImagesUnavailable('Cloudflare Images is unavailable.', previous: $exception);
        }

        if (! $response->successful() || $response->json('success') !== true) {
            throw new CloudflareImagesUnavailable('Cloudflare Images rejected the upload request.');
        }

        $id = $response->json('result.id');
        $uploadUrl = $response->json('result.uploadURL');

        if (! is_string($id)
            || ! preg_match('/^[A-Za-z0-9_-]+$/', $id)
            || ! is_string($uploadUrl)
            || ! $this->isTrustedUploadUrl($uploadUrl)) {
            throw new CloudflareImagesUnavailable('Cloudflare Images returned an invalid upload response.');
        }

        return [
            'id' => $id,
            'upload_url' => $uploadUrl,
            'delivery_url' => "{$deliveryBaseUrl}/{$id}/{$variant}",
            'expires_at' => $expiresAt,
            'method' => 'POST',
            'headers' => [],
        ];
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function isTrustedUploadUrl(string $url): bool
    {
        return $this->isHttpsUrl($url)
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === 'upload.imagedelivery.net';
    }
}
