<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\DocumentSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private function docAuth(): array
    {
        ['plainText' => $plainText] = ApiToken::issue('doc-agent', ['documents:read']);

        return ['Authorization' => 'Bearer '.$plainText];
    }

    private function genericReadAuth(): array
    {
        ['plainText' => $plainText] = ApiToken::issue('read-only-bot', ['read']);

        return ['Authorization' => 'Bearer '.$plainText];
    }

    private function createDocument(array $attributes = []): DocumentSource
    {
        $user = User::factory()->create();

        return DocumentSource::create(array_merge([
            'user_id' => $user->id,
            'source_type' => DocumentSource::SOURCE_TYPE_UPLOAD,
            'original_filename' => 'contract.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'document-sources/test-uuid.pdf',
            'file_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'page_count' => 3,
            'status' => DocumentSource::STATUS_READY,
            'failure_reason' => null,
            'meta' => ['internal_debug' => 'secret_blob'],
        ], $attributes));
    }

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/api/v1/documents')->assertStatus(401);
        $this->getJson('/api/v1/documents/1')->assertStatus(401);
    }

    public function test_generic_read_ability_fails_with_403(): void
    {
        $this->getJson('/api/v1/documents', $this->genericReadAuth())
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have the required ability.');

        $this->getJson('/api/v1/documents/1', $this->genericReadAuth())
            ->assertStatus(403)
            ->assertJsonPath('message', 'This token does not have the required ability.');
    }

    public function test_documents_read_ability_succeeds(): void
    {
        $this->createDocument();

        $response = $this->getJson('/api/v1/documents', $this->docAuth());

        $response->assertOk()
            ->assertJsonPath('meta.version', '1.0')
            ->assertJsonPath('meta.count', 1);
    }

    public function test_list_returns_only_safe_allowlisted_metadata_and_redacts_private_fields(): void
    {
        $doc = $this->createDocument([
            'original_filename' => 'safe_listing.pdf',
            'file_size' => 4096,
            'page_count' => 5,
            'status' => DocumentSource::STATUS_READY,
        ]);

        $response = $this->getJson('/api/v1/documents', $this->docAuth());

        $response->assertOk();
        $item = $response->json('data.0');

        // Allowlisted fields must be present
        $this->assertSame($doc->id, $item['id']);
        $this->assertSame('upload', $item['source_type']);
        $this->assertSame('safe_listing.pdf', $item['original_filename']);
        $this->assertSame('application/pdf', $item['mime_type']);
        $this->assertSame(4096, $item['file_size']);
        $this->assertSame('ready', $item['status']);
        $this->assertSame(5, $item['page_count']);
        $this->assertArrayHasKey('created_at', $item);
        $this->assertArrayHasKey('updated_at', $item);
        $this->assertArrayHasKey('failure_category', $item);

        // failure_reason must NEVER be exposed
        $this->assertArrayNotHasKey('failure_reason', $item);

        // Sensitive/private fields must be strictly excluded
        $this->assertArrayNotHasKey('storage_path', $item);
        $this->assertArrayNotHasKey('storage_disk', $item);
        $this->assertArrayNotHasKey('file_hash', $item);
        $this->assertArrayNotHasKey('meta', $item);
        $this->assertArrayNotHasKey('user_id', $item);
        $this->assertArrayNotHasKey('user', $item);
    }

    public function test_failure_category_maps_known_enums_and_collapses_unknown_text(): void
    {
        $known = $this->createDocument([
            'status' => DocumentSource::STATUS_FAILED,
            'failure_reason' => DocumentSource::FAILURE_INVALID_PDF,
        ]);

        $rawInternal = $this->createDocument([
            'status' => DocumentSource::STATUS_FAILED,
            'failure_reason' => 'InternalException: /secret/path/corrupt_file.bin line 42 crashed',
        ]);

        $noFailure = $this->createDocument([
            'status' => DocumentSource::STATUS_READY,
            'failure_reason' => null,
        ]);

        // Detail check for known category
        $this->getJson('/api/v1/documents/'.$known->id, $this->docAuth())
            ->assertOk()
            ->assertJsonPath('data.failure_category', 'invalid_pdf')
            ->assertJsonMissing(['failure_reason']);

        // Detail check for raw internal text safely collapsed to processing_error
        $this->getJson('/api/v1/documents/'.$rawInternal->id, $this->docAuth())
            ->assertOk()
            ->assertJsonPath('data.failure_category', 'processing_error')
            ->assertJsonMissing(['failure_reason'])
            ->assertDontSee('/secret/path');

        // Detail check for successful document without failure
        $this->getJson('/api/v1/documents/'.$noFailure->id, $this->docAuth())
            ->assertOk()
            ->assertJsonPath('data.failure_category', null)
            ->assertJsonMissing(['failure_reason']);
    }

    public function test_status_filter_accepts_valid_statuses(): void
    {
        $this->createDocument(['status' => DocumentSource::STATUS_UPLOADED]);
        $this->createDocument(['status' => DocumentSource::STATUS_READY]);
        $this->createDocument(['status' => DocumentSource::STATUS_FAILED, 'failure_reason' => DocumentSource::FAILURE_INVALID_PDF]);

        $response = $this->getJson('/api/v1/documents?status=uploaded', $this->docAuth());
        $response->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.status', 'uploaded');

        $response = $this->getJson('/api/v1/documents?status=failed', $this->docAuth());
        $response->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.failure_category', 'invalid_pdf');
    }

    public function test_status_filter_rejects_invalid_status_and_cancelled_status(): void
    {
        $this->getJson('/api/v1/documents?status=invalid_status', $this->docAuth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 'cancelled' is intentionally excluded from the agent allowlist
        $this->getJson('/api/v1/documents?status=cancelled', $this->docAuth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_pagination_bounds_and_validation(): void
    {
        $this->getJson('/api/v1/documents?limit=0', $this->docAuth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);

        $this->getJson('/api/v1/documents?limit=51', $this->docAuth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);

        $this->getJson('/api/v1/documents?page=0', $this->docAuth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);

        // Page max bound: 1000
        $this->getJson('/api/v1/documents?page=1001', $this->docAuth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);

        $this->getJson('/api/v1/documents?page=1000', $this->docAuth())
            ->assertOk();
    }

    public function test_cancelled_documents_are_never_returned_in_list(): void
    {
        $this->createDocument(['status' => DocumentSource::STATUS_CANCELLED]);
        $active = $this->createDocument(['status' => DocumentSource::STATUS_READY]);

        $response = $this->getJson('/api/v1/documents', $this->docAuth());

        $response->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_detail_endpoint_returns_safe_allowlisted_metadata(): void
    {
        $doc = $this->createDocument([
            'original_filename' => 'single_detail.pdf',
            'file_size' => 1024,
            'page_count' => 1,
            'status' => DocumentSource::STATUS_READY,
        ]);

        $response = $this->getJson('/api/v1/documents/'.$doc->id, $this->docAuth());

        $response->assertOk()
            ->assertJsonPath('meta.version', '1.0')
            ->assertJsonPath('data.id', $doc->id)
            ->assertJsonPath('data.original_filename', 'single_detail.pdf')
            ->assertJsonPath('data.page_count', 1)
            ->assertJsonMissing(['failure_reason']);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('failure_reason', $data);
        $this->assertArrayNotHasKey('storage_path', $data);
        $this->assertArrayNotHasKey('storage_disk', $data);
        $this->assertArrayNotHasKey('file_hash', $data);
        $this->assertArrayNotHasKey('meta', $data);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('user', $data);
    }

    public function test_detail_endpoint_returns_404_for_cancelled_document(): void
    {
        $doc = $this->createDocument(['status' => DocumentSource::STATUS_CANCELLED]);

        $this->getJson('/api/v1/documents/'.$doc->id, $this->docAuth())
            ->assertNotFound();
    }

    public function test_detail_endpoint_returns_404_for_nonexistent_or_invalid_id(): void
    {
        $this->getJson('/api/v1/documents/999999', $this->docAuth())
            ->assertNotFound();

        $this->getJson('/api/v1/documents/invalid-id', $this->docAuth())
            ->assertNotFound();

        $this->getJson('/api/v1/documents/-1', $this->docAuth())
            ->assertNotFound();
    }
}
