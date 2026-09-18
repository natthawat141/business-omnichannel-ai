<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class FirebaseTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com';

    public function __construct(
        private readonly ?string $projectId = null,
    ) {}

    public function getProjectId(): string
    {
        return $this->projectId ?: (string) config('services.firebase.project_id', 'monica-88d72');
    }

    /**
     * Verify a Firebase ID Token and return claims.
     *
     * @return array{uid: string, email: string|null, email_verified: bool, name: string|null}
     *
     * @throws ValidationException
     */
    public function verify(string $idToken): array
    {
        $projectId = $this->getProjectId();

        try {
            $jwks = $this->fetchJwks();
            $keys = JWK::parseKeySet($jwks);
            $decoded = JWT::decode($idToken, $keys);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'id_token' => 'โทเค็นไม่ถูกต้องหรือไม่สามารถยืนยันตัวตนได้: '.$e->getMessage(),
            ]);
        }

        $expectedIssuer = "https://securetoken.google.com/{$projectId}";

        if (($decoded->aud ?? '') !== $projectId) {
            throw ValidationException::withMessages([
                'id_token' => 'Audience ในโทเค็นไม่ตรงกับโปรเจกต์ที่กำหนด',
            ]);
        }

        if (($decoded->iss ?? '') !== $expectedIssuer) {
            throw ValidationException::withMessages([
                'id_token' => 'Issuer ในโทเค็นไม่ถูกต้อง',
            ]);
        }

        if (empty($decoded->sub) || ! is_string($decoded->sub)) {
            throw ValidationException::withMessages([
                'id_token' => 'ไม่พบ Subject (UID) ในโทเค็น',
            ]);
        }

        return [
            'uid' => (string) $decoded->sub,
            'email' => isset($decoded->email) ? (string) $decoded->email : null,
            'email_verified' => (bool) ($decoded->email_verified ?? false),
            'name' => isset($decoded->name) ? (string) $decoded->name : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchJwks(): array
    {
        return Cache::remember('firebase_jwks_keyset', 21600, function () {
            $response = Http::timeout(10)->get(self::JWKS_URL);
            if (! $response->successful()) {
                throw new \RuntimeException('ไม่สามารถดาวน์โหลด Public Keys จาก Google ได้');
            }
            return $response->json();
        });
    }
}
