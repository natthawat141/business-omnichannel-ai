<?php

namespace Tests\Feature;

use App\Http\Resources\PackageResource;
use App\Models\ApiToken;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogMapUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_read_update_preserve_and_clear_map_link(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/packages', ['name_th' => 'Synthetic location',
            'map_url' => 'https://maps.app.goo.gl/example',
        ])->assertRedirect('/admin/packages')->assertSessionHasNoErrors();
        $item = ServicePackage::firstOrFail();
        $this->assertSame('https://maps.app.goo.gl/example', $item->map_url);
        $this->assertSame($item->map_url, (new PackageResource($item))->resolve()['map_url']);

        $this->put('/admin/packages/'.$item->id, ['name_th' => $item->name_th,
            'map_url' => 'https://www.google.com/maps?q=Bangkok',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->put('/admin/packages/'.$item->id, ['name_th' => 'Changed title'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('https://www.google.com/maps?q=Bangkok', $item->fresh()->map_url);
        $this->put('/admin/packages/'.$item->id, ['name_th' => 'Changed title', 'map_url' => null])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->map_url);
    }

    public function test_admin_and_agent_reject_unsafe_or_oversized_links_without_writing(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        ['plainText' => $key] = ApiToken::issue('map-test', ['agent:read', 'changes:write', 'agent:catalog']);
        foreach (['http://maps.google.com/', 'javascript:alert(1)', 'not a url', 'https://example.com/'.str_repeat('a', 2048)] as $url) {
            $this->post('/admin/packages', ['name_th' => 'Invalid', 'map_url' => $url])
                ->assertSessionHasErrors('map_url');
            $this->withToken($key)->postJson('/api/v1/agent/changes/preview', ['operations' => [[
                'entity' => 'catalog', 'action' => 'create', 'client_ref' => 'map',
                'payload' => ['name_th' => 'Invalid', 'map_url' => $url],
            ]]])->assertUnprocessable()->assertJsonValidationErrors('operations.0.payload.map_url');
        }
        $this->assertDatabaseCount('packages', 0);
    }

    public function test_model_boundary_rejects_unsafe_map_links(): void
    {
        $this->expectException(ValidationException::class);
        ServicePackage::create(['name_th' => 'Invalid', 'map_url' => 'javascript:alert(1)']);
    }

    public function test_agent_discovers_and_previews_map_without_creating_live_record(): void
    {
        ['plainText' => $key] = ApiToken::issue('map-test', ['agent:read', 'changes:write', 'agent:catalog']);
        $this->withToken($key)->getJson('/api/v1/agent/schema')->assertOk()
            ->assertJsonPath('data.catalog_link_fields.map_url.scheme', 'https');
        $this->withToken($key)->postJson('/api/v1/agent/changes/preview', ['operations' => [[
            'entity' => 'catalog', 'action' => 'create', 'client_ref' => 'map',
            'payload' => ['name_th' => 'Synthetic map', 'map_url' => 'https://maps.app.goo.gl/example'],
        ]]])->assertOk();
        $this->assertDatabaseCount('packages', 0);
    }

    public function test_viewer_cannot_write_map_link(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false, 'role' => 'viewer']))
            ->post('/admin/packages', ['name_th' => 'Forbidden', 'map_url' => 'https://maps.app.goo.gl/example'])
            ->assertForbidden();
        $this->assertDatabaseCount('packages', 0);
    }

    public function test_map_proposal_needs_admin_apply_and_is_readable_afterward(): void
    {
        ['plainText' => $key] = ApiToken::issue('map-proposal', ['agent:read', 'changes:write', 'agent:catalog']);
        $map = 'https://maps.app.goo.gl/example';
        $id = $this->withToken($key)->postJson('/api/v1/agent/changes', ['operations' => [[
            'entity' => 'catalog', 'action' => 'create', 'client_ref' => 'map',
            'payload' => ['name_th' => 'Synthetic map', 'map_url' => $map],
        ]]], ['Idempotency-Key' => 'map-create'])->assertSuccessful()->json('data.id');
        $this->assertDatabaseCount('packages', 0);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/agent-changes/'.$id.'/approve')->assertRedirect();
        $this->post('/admin/agent-changes/'.$id.'/apply')->assertRedirect();
        $item = ServicePackage::firstOrFail();
        $this->assertSame($map, $item->map_url);
        $this->assertFalse($item->is_published);
        $this->withToken($key)->getJson('/api/v1/agent/records/catalog/'.$item->id)
            ->assertOk()->assertJsonPath('data.map_url', $map);

        ['plainText' => $reader] = ApiToken::issue('catalog-reader', ['read']);
        $this->withToken($reader)->getJson('/api/v1/catalog/'.$item->id)->assertNotFound();
        $item->update(['is_active' => true, 'is_published' => true, 'availability' => 'available']);
        $this->withToken($reader)->getJson('/api/v1/catalog/'.$item->id)
            ->assertOk()->assertJsonPath('data.map_url', $map);
    }
}
