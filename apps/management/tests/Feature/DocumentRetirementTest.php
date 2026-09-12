<?php

namespace Tests\Feature;

use App\Models\DocumentSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_page_and_all_write_endpoints_are_retired(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/documents')->assertStatus(410);
        $this->get('/admin/documents/1')->assertStatus(410);
        $this->post('/admin/documents', ['file' => UploadedFile::fake()->create('sample.pdf', 1, 'application/pdf')])->assertStatus(410);
        $this->post('/admin/documents/1/cancel')->assertStatus(410);
        $this->delete('/admin/documents/1')->assertStatus(410);
        $this->assertDatabaseCount('document_sources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_existing_evidence_and_private_file_are_preserved(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['is_admin' => true]);
        $source = DocumentSource::create(['user_id' => $user->id, 'source_type' => 'upload',
            'original_filename' => 'existing.pdf', 'storage_disk' => 'local', 'storage_path' => 'document-sources/existing.pdf',
            'file_hash' => 'synthetic', 'mime_type' => 'application/pdf', 'file_size' => 10, 'status' => 'uploaded']);
        Storage::disk('local')->put($source->storage_path, 'synthetic file');
        $this->actingAs($user)->delete('/admin/documents/'.$source->id)->assertStatus(410);
        $this->assertDatabaseHas('document_sources', ['id' => $source->id, 'status' => 'uploaded']);
        Storage::disk('local')->assertExists($source->storage_path);
    }

    public function test_navigation_no_longer_offers_document_intake(): void
    {
        $layout = file_get_contents(resource_path('js/components/AdminLayout.tsx'));
        $this->assertStringNotContainsString('href: routes.documents.index', $layout);
    }

    public function test_authentication_and_staff_boundaries_remain(): void
    {
        $this->get('/admin/documents')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['is_admin' => false, 'role' => 'editor']))
            ->get('/admin/documents')->assertForbidden();
    }
}
