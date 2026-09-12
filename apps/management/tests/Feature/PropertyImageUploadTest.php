<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PropertyImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_ID = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.cloudflare_images.account_id' => self::ACCOUNT_ID,
            'services.cloudflare_images.api_token' => 'test-cloudflare-token',
            'services.cloudflare_images.delivery_base_url' => 'https://imagedelivery.net/test-account-hash',
            'services.cloudflare_images.variant' => 'public',
            'services.cloudflare_images.direct_upload_expiry_minutes' => 10,
        ]);
    }

    public function test_guest_cannot_request_a_direct_upload_url(): void
    {
        Http::preventStrayRequests();

        $this->post('/admin/property-images/direct-upload')
            ->assertRedirect('/login');

        Http::assertNothingSent();
    }

    public function test_non_admin_cannot_request_a_direct_upload_url(): void
    {
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->postJson('/admin/property-images/direct-upload', ['content_type' => 'image/jpeg'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_admin_receives_a_short_lived_direct_upload_url_without_the_api_token(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT_ID.'/images/v2/direct_upload' => Http::response([
                'result' => [
                    'id' => '2cdc28f0-017a-49c4-9ed7-87056c83901',
                    'uploadURL' => 'https://upload.imagedelivery.net/test-account-hash/2cdc28f0-017a-49c4-9ed7-87056c83901',
                ],
                'success' => true,
                'errors' => [],
                'messages' => [],
            ], 200),
        ]);

        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/admin/property-images/direct-upload', ['content_type' => 'image/jpeg'])
            ->assertCreated()
            ->assertJsonPath('data.id', '2cdc28f0-017a-49c4-9ed7-87056c83901')
            ->assertJsonPath(
                'data.upload_url',
                'https://upload.imagedelivery.net/test-account-hash/2cdc28f0-017a-49c4-9ed7-87056c83901'
            )
            ->assertJsonPath(
                'data.delivery_url',
                'https://imagedelivery.net/test-account-hash/2cdc28f0-017a-49c4-9ed7-87056c83901/public'
            );

        $response->assertDontSee('test-cloudflare-token');

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT_ID.'/images/v2/direct_upload'
                && $request->hasHeader('Authorization', 'Bearer test-cloudflare-token')
                && str_contains((string) $request->header('Content-Type')[0], 'multipart/form-data')
                && str_contains($request->body(), 'requireSignedURLs')
                && str_contains($request->body(), 'expiry');
        });
    }

    public function test_missing_cloudflare_configuration_fails_closed_without_an_upstream_request(): void
    {
        config(['services.cloudflare_images.api_token' => null]);
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/admin/property-images/direct-upload', ['content_type' => 'image/jpeg'])
            ->assertStatus(503)
            ->assertJson([
                'message' => 'ยังไม่สามารถเริ่มอัปโหลดรูปได้ กรุณาตรวจการตั้งค่าที่เก็บรูป',
            ]);

        Http::assertNothingSent();
    }

    public function test_cloudflare_failure_returns_a_sanitized_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'upstream detail must stay private']],
            ], 403),
        ]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/admin/property-images/direct-upload', ['content_type' => 'image/jpeg'])
            ->assertStatus(503)
            ->assertJson([
                'message' => 'ยังไม่สามารถเริ่มอัปโหลดรูปได้ กรุณาตรวจการตั้งค่าที่เก็บรูป',
            ])
            ->assertDontSee('upstream detail must stay private');
    }

    public function test_untrusted_upload_url_from_upstream_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => [
                    'id' => '2cdc28f0-017a-49c4-9ed7-87056c83901',
                    'uploadURL' => 'https://evil.example/upload',
                ],
                'success' => true,
            ], 200),
        ]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/admin/property-images/direct-upload', ['content_type' => 'image/jpeg'])
            ->assertStatus(503);
    }

    public function test_r2_returns_a_content_type_bound_presigned_put_without_exposing_the_secret(): void
    {
        config([
            'services.property_images.driver' => 'r2',
            'services.cloudflare_r2.direct_upload_expiry_minutes' => 10,
            'filesystems.disks.r2.key' => 'r2-test-access-key',
            'filesystems.disks.r2.secret' => 'r2-test-secret',
            'filesystems.disks.r2.bucket' => 'monica',
            'filesystems.disks.r2.endpoint' => 'https://example-account.r2.cloudflarestorage.com',
            'filesystems.disks.r2.url' => 'https://images.example.com',
        ]);

        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/admin/property-images/direct-upload', ['content_type' => 'image/webp'])
            ->assertCreated()
            ->assertJsonPath('data.method', 'PUT')
            ->assertJsonPath('data.headers.Content-Type', 'image/webp')
            ->assertJsonMissingPath('data.headers.Host');

        $this->assertMatchesRegularExpression(
            '#^https://images\.example\.com/properties/[0-9a-f-]+\.webp$#',
            (string) $response->json('data.delivery_url')
        );

        $uploadUrl = (string) $response->json('data.upload_url');
        $this->assertMatchesRegularExpression(
            '#^https://example-account\.r2\.cloudflarestorage\.com/monica/properties/[0-9a-f-]+\.webp\?#',
            $uploadUrl
        );
        parse_str((string) parse_url($uploadUrl, PHP_URL_QUERY), $query);
        $this->assertSame('content-type;host', $query['X-Amz-SignedHeaders'] ?? null);
        $this->assertStringStartsWith(
            'r2-test-access-key/',
            (string) ($query['X-Amz-Credential'] ?? '')
        );
        $response->assertDontSee('r2-test-secret');
    }

    public function test_r2_rejects_unsupported_content_type_before_signing(): void
    {
        config(['services.property_images.driver' => 'r2']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/admin/property-images/direct-upload', ['content_type' => 'image/svg+xml'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_type');
    }

    public function test_unknown_image_driver_fails_closed(): void
    {
        config(['services.property_images.driver' => 'unknown']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/admin/property-images/direct-upload', ['content_type' => 'image/png'])
            ->assertStatus(503)
            ->assertJson([
                'message' => 'ยังไม่สามารถเริ่มอัปโหลดรูปได้ กรุณาตรวจการตั้งค่าที่เก็บรูป',
            ]);
    }
}
