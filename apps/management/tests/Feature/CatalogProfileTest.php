<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Agent\AgentChangeService;
use App\Services\Catalog\CatalogWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogProfileTest extends TestCase
{
    use RefreshDatabase;

    private function token(): array
    {
        return ApiToken::issue('profile-test', ['agent:read', 'changes:write', 'agent:catalog']);
    }

    public function test_agent_discovers_versioned_profiles_and_field_constraints(): void
    {
        ['plainText' => $key] = $this->token();
        $this->withToken($key)->getJson('/api/v1/agent/schema')->assertOk()
            ->assertJsonPath('data.catalog_profiles.property_project.record_kind', 'group')
            ->assertJsonPath('data.catalog_profiles.property_project.version', 1)
            ->assertJsonPath('data.catalog_profiles.property_layout.fields.area_range.unit', 'sqm');
    }

    public function test_project_data_survives_admin_edits_and_offer_can_attach_directly(): void
    {
        $project = ServicePackage::create([
            'name_th' => 'Synthetic project', 'record_kind' => 'group',
            'profile' => 'property_project',
            'profile_data' => ['developer' => 'Example', 'facilities' => ['pool', 'gym'], 'unit_count' => 444],
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->put('/admin/packages/'.$project->id, [
            'name_th' => 'Updated project', 'lock_version' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(444, $project->fresh()->profile_data['unit_count']);
        $offer = ServicePackage::create(['name_th' => 'Unit A', 'record_kind' => 'offer', 'parent_id' => $project->id]);
        $this->assertSame($project->id, $offer->parent_id);
    }

    public function test_profile_cannot_be_attached_to_wrong_kind_or_contain_offer_price(): void
    {
        ['plainText' => $key] = $this->token();
        $this->withToken($key)->postJson('/api/v1/agent/changes/preview', ['operations' => [[
            'entity' => 'catalog', 'action' => 'create', 'client_ref' => 'invalid',
            'payload' => ['name_th' => 'Invalid', 'record_kind' => 'offer', 'profile' => 'property_project',
                'profile_data' => ['price' => 100]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['operations.0.payload.profile', 'operations.0.payload.profile_data.price']);
    }

    public function test_profile_validation_rejects_bad_types_ranges_dates_coordinates_and_facilities(): void
    {
        ['plainText' => $key] = $this->token();
        foreach ([
            'unit_count' => -1, 'building_count' => '1', 'latitude' => 91, 'longitude' => 181,
            'construction_status_as_of' => '2026-02-30', 'facilities' => ['invented_facility'],
            'construction_status' => 'complete_by_guess',
        ] as $field => $value) {
            $this->withToken($key)->postJson('/api/v1/agent/changes/preview', ['operations' => [[
                'entity' => 'catalog', 'action' => 'create', 'client_ref' => 'invalid',
                'payload' => ['name_th' => 'Invalid', 'record_kind' => 'group', 'profile' => 'property_project',
                    'profile_data' => [$field => $value]],
            ]]])->assertUnprocessable();
        }
        $this->withToken($key)->postJson('/api/v1/agent/changes/preview', ['operations' => [[
            'entity' => 'catalog', 'action' => 'create', 'client_ref' => 'invalid',
            'payload' => ['name_th' => 'Invalid', 'record_kind' => 'variant', 'profile' => 'property_layout',
                'profile_data' => ['area_range' => ['min' => 48, 'max' => 34.5, 'unit' => 'sqm']]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors('operations.0.payload.profile_data.area_range');
        $this->assertDatabaseCount('packages', 0);
    }

    public function test_model_write_revalidates_profile_when_record_kind_changes(): void
    {
        $project = ServicePackage::create(['name_th' => 'Project', 'record_kind' => 'group',
            'profile' => 'property_project', 'profile_data' => ['developer' => 'Example']]);
        $this->expectException(ValidationException::class);
        app(CatalogWriter::class)->update($project, ['record_kind' => 'offer'], 1);
    }

    public function test_staff_profile_search_returns_only_exact_matches_and_rejects_invalid_filters(): void
    {
        $project = ServicePackage::create(['name_th' => 'With pool', 'record_kind' => 'group',
            'profile' => 'property_project', 'profile_data' => ['facilities' => ['pool']]]);
        ServicePackage::create(['name_th' => 'Without pool', 'record_kind' => 'group',
            'profile' => 'property_project', 'profile_data' => ['facilities' => ['gym']]]);
        ['plainText' => $key] = $this->token();
        $this->withToken($key)->getJson('/api/v1/agent/records/catalog?profile=property_project&facility=pool&limit=1')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $project->id)
            ->assertJsonPath('data.items.0.profile_data.facilities.0', 'pool');
        $this->withToken($key)->getJson('/api/v1/agent/records/catalog?profile=property_project&facility=spa')
            ->assertOk()->assertJsonCount(0, 'data.items');
        foreach (['profile=root', 'facility=pool', 'profile=property_project&facility=sql', 'parent_id=0', 'record_kind=bogus'] as $filter) {
            $this->withToken($key)->getJson('/api/v1/agent/records/catalog?'.$filter)->assertUnprocessable();
        }
    }

    public function test_brochure_example_applies_only_project_and_layout_drafts_with_evidence(): void
    {
        ['token' => $token] = $this->token();
        $example = json_decode(file_get_contents(base_path('tests/Fixtures/coco-parc-proposal.json')), true, 32, JSON_THROW_ON_ERROR);
        $service = app(AgentChangeService::class);
        $service->preview($token, $example);
        $set = $service->submit($token, $example, 'coco-brochure-example')['change_set'];
        $this->assertDatabaseCount('packages', 0);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/agent-changes/'.$set->id)
            ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('AgentChanges/Show')
                ->where('changeSet.operations.0.payload.profile_data.unit_count', 444)
                ->where('changeSet.operations.0.sources.0.page', 4)
                ->has('changeSet.operations.0.preview'));
        $service->approve($set, $admin);
        $service->apply($set, $admin);
        $this->assertDatabaseCount('packages', 7);
        $this->assertSame(0, ServicePackage::publicOffer()->count());
        $this->assertSame(0, ServicePackage::where('is_published', true)->count());
        $project = ServicePackage::where('profile', 'property_project')->firstOrFail();
        $this->assertSame(444, $project->profile_data['unit_count']);
        $this->assertNull($project->price);
        $this->assertSame('unknown', $project->profile_data['construction_status']);
        $this->assertSame(6, $project->children()->count());
        $this->assertDatabaseHas('record_sources', ['entity_id' => $project->id, 'page' => 13, 'verified' => false]);
        ['plainText' => $read] = ApiToken::issue('public-test', ['read']);
        $this->withToken($read)->postJson('/api/v1/catalog/search', ['limit' => 10])
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_form_discovers_profiles_and_saves_structured_values(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/packages/create')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Packages/Form')->where('profileSchemas.property_project.record_kind', 'group'));
        $this->post('/admin/packages', ['name_th' => 'Example', 'record_kind' => 'group', 'profile' => 'property_project',
            'profile_data' => ['building_count' => 1, 'construction_status' => 'unknown', 'facilities' => ['pool']]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $project = ServicePackage::firstOrFail();
        $this->assertSame(1, $project->profile_data['building_count']);
        $this->put('/admin/packages/'.$project->id, ['name_th' => 'Example', 'lock_version' => $project->lock_version,
            'profile_data' => ['building_count' => 'invalid']])->assertSessionHasErrors('profile_data.building_count');
        $this->assertSame(1, $project->fresh()->profile_data['building_count']);
    }

    public function test_profile_update_preserves_omitted_data_and_checks_effective_record(): void
    {
        $project = ServicePackage::create(['name_th' => 'Example', 'record_kind' => 'group',
            'profile' => 'property_project', 'profile_data' => ['developer' => 'Example']]);
        ['plainText' => $key] = $this->token();
        $operation = ['entity' => 'catalog', 'action' => 'update', 'target_id' => $project->id, 'expected_version' => $project->lock_version];
        $this->withToken($key)->postJson('/api/v1/agent/changes/preview', ['operations' => [
            $operation + ['payload' => ['name_th' => 'Renamed']],
        ]])->assertOk();
        foreach ([['price' => 100], ['profile' => null], ['record_kind' => 'offer']] as $payload) {
            $this->withToken($key)->postJson('/api/v1/agent/changes/preview', ['operations' => [$operation + ['payload' => $payload]]])
                ->assertUnprocessable();
        }
        $this->assertSame('Example', $project->fresh()->profile_data['developer']);
    }

    public function test_profile_version_change_blocks_apply_without_creating_records(): void
    {
        ['token' => $token] = $this->token();
        $service = app(AgentChangeService::class);
        $example = json_decode(file_get_contents(base_path('tests/Fixtures/coco-parc-proposal.json')), true, 32, JSON_THROW_ON_ERROR);
        $set = $service->submit($token, $example, 'version-change-test')['change_set'];
        $this->assertSame(1, $set->schema_versions['profile:property_project']);
        $set->update(['schema_versions' => ['profile:property_project' => 0, 'profile:property_layout' => 1]]);
        $admin = User::factory()->create(['is_admin' => true]);
        $service->approve($set, $admin);
        try {
            $service->apply($set, $admin);
            $this->fail('A stale profile schema must not apply.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('change_set', $exception->errors());
        }
        $this->assertDatabaseCount('packages', 0);
        $this->assertSame('conflicted', $set->fresh()->status);
    }

    public function test_offer_proposal_can_attach_directly_to_a_project(): void
    {
        ['token' => $token] = $this->token();
        $project = ServicePackage::create(['name_th' => 'Example', 'record_kind' => 'group', 'profile' => 'property_project']);
        $service = app(AgentChangeService::class);
        $set = $service->submit($token, ['operations' => [[
            'entity' => 'catalog', 'action' => 'create', 'client_ref' => 'unit',
            'payload' => ['name_th' => 'Synthetic unit', 'record_kind' => 'offer', 'parent_id' => $project->id,
                'price' => 100, 'availability' => 'available'],
        ]]], 'direct-offer-test')['change_set'];
        $admin = User::factory()->create(['is_admin' => true]);
        $service->approve($set, $admin);
        $service->apply($set, $admin);
        $offer = $project->children()->firstOrFail();
        $this->assertSame('offer', $offer->record_kind);
        $this->assertSame('100.00', $offer->price);
        $this->assertFalse($offer->is_published);
        $this->assertNull($offer->profile);
    }
}
