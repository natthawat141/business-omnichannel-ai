<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\DocumentSource;
use App\Models\Faq;
use App\Models\KnowledgeEntry;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentChangesetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, string> $abilities */
    private function token(array $abilities = ['agent:read', 'changes:write', 'agent:catalog']): array
    {
        return ApiToken::issue('proposal-test', $abilities);
    }

    private function headers(string $plain, string $idempotency = 'proposal-test-001'): array
    {
        return ['Authorization' => 'Bearer '.$plain, 'Idempotency-Key' => $idempotency];
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** @return array<string, mixed> */
    private function catalogProposal(): array
    {
        return ['operations' => [
            ['entity' => 'catalog', 'action' => 'create', 'client_ref' => 'project', 'payload' => [
                'name_th' => 'COCO PARC', 'record_kind' => 'group', 'location_text' => 'พระราม 4',
            ], 'sources' => [['external_label' => 'COCO PARC brochure', 'page' => 13, 'field_paths' => ['name_th', 'location_text']]]],
            ['entity' => 'catalog', 'action' => 'create', 'client_ref' => 'studio', 'parent_client_ref' => 'project', 'payload' => [
                'name_th' => 'Studio 25.5–27 sqm', 'record_kind' => 'variant',
                'attributes' => null,
            ]],
            ['entity' => 'catalog', 'action' => 'create', 'client_ref' => 'sample-offer', 'parent_client_ref' => 'studio', 'payload' => [
                'name_th' => 'Draft offer requiring availability confirmation', 'record_kind' => 'offer',
            ]],
        ]];
    }

    public function test_old_document_key_cannot_discover_or_write_agent_data(): void
    {
        ['plainText' => $plain] = $this->token(['documents:read']);
        $this->getJson('/api/v1/agent/schema', $this->headers($plain))->assertForbidden();
        $this->postJson('/api/v1/agent/changes/preview', $this->catalogProposal(), $this->headers($plain))->assertForbidden();
    }

    public function test_schema_is_scoped_and_never_exposes_sql_or_storage_secrets(): void
    {
        ['plainText' => $plain] = $this->token();
        $response = $this->getJson('/api/v1/agent/schema', $this->headers($plain))->assertOk();
        $response->assertJsonPath('data.entities.0.name', 'catalog')
            ->assertJsonMissingPath('data.database')
            ->assertJsonMissingPath('data.sql');
    }

    public function test_scoped_agent_can_only_read_bounded_allowlisted_records(): void
    {
        $package = ServicePackage::factory()->create(['name_th' => 'Bounded record', 'lock_version' => 7]);
        ['plainText' => $plain] = $this->token();

        $this->getJson('/api/v1/agent/records/catalog?query=Bounded&limit=1', $this->headers($plain))
            ->assertOk()->assertJsonPath('data.entity', 'catalog')
            ->assertJsonPath('data.items.0.id', $package->id)
            ->assertJsonPath('data.items.0.lock_version', 7)
            ->assertJsonMissingPath('data.items.0.storage_path');
        $this->getJson('/api/v1/agent/records/catalog/'.$package->id, $this->headers($plain))
            ->assertOk()->assertJsonPath('data.id', $package->id);
        $this->getJson('/api/v1/agent/records/faq', $this->headers($plain))->assertNotFound();
        $this->getJson('/api/v1/agent/records/catalog?limit=51', $this->headers($plain))->assertUnprocessable();
    }

    public function test_preview_rejects_publish_flags_unknown_fields_and_fake_source_paths(): void
    {
        ['plainText' => $plain] = $this->token();
        $proposal = $this->catalogProposal();
        $proposal['operations'][0]['payload']['is_published'] = true;
        $proposal['operations'][0]['payload']['drop_table'] = 'packages';
        $proposal['operations'][0]['sources'] = [['storage_path' => '/private/file.pdf']];
        $this->postJson('/api/v1/agent/changes/preview', $proposal, $this->headers($plain))
            ->assertUnprocessable()->assertJsonValidationErrors([
                'operations.0.payload.is_published', 'operations.0.payload.drop_table', 'operations.0.sources.0',
            ]);
        $this->assertDatabaseCount('agent_change_sets', 0);
        $this->assertDatabaseCount('packages', 0);
    }

    public function test_submit_is_immutable_idempotent_and_does_not_write_business_records(): void
    {
        ['plainText' => $plain] = $this->token();
        $proposal = $this->catalogProposal();
        $first = $this->postJson('/api/v1/agent/changes', $proposal, $this->headers($plain, 'same-key'))->assertCreated();
        $id = $first->json('data.id');
        $this->assertSame('proposed', $first->json('data.status'));
        $this->assertDatabaseCount('packages', 0);
        $this->assertDatabaseCount('agent_change_operations', 3);
        $this->postJson('/api/v1/agent/changes', $proposal, $this->headers($plain, 'same-key'))
            ->assertOk()->assertJsonPath('data.id', $id);
        $proposal['operations'][0]['payload']['name_th'] = 'Different';
        $this->postJson('/api/v1/agent/changes', $proposal, $this->headers($plain, 'same-key'))->assertConflict();
    }

    public function test_only_the_issuing_agent_can_read_its_proposal(): void
    {
        ['plainText' => $plain] = $this->token();
        $id = $this->postJson('/api/v1/agent/changes', $this->catalogProposal(), $this->headers($plain))->json('data.id');
        ['plainText' => $other] = $this->token();
        $this->getJson('/api/v1/agent/changes/'.$id, $this->headers($other))->assertNotFound();
        $this->getJson('/api/v1/agent/changes/'.$id, $this->headers($plain))->assertOk()
            ->assertJsonPath('data.operations.0.action', 'create');
    }

    public function test_admin_approval_then_apply_creates_drafts_with_parent_refs_and_sources(): void
    {
        ['plainText' => $plain] = $this->token();
        $id = $this->postJson('/api/v1/agent/changes', $this->catalogProposal(), $this->headers($plain))->json('data.id');
        $this->actingAs($this->admin())->post('/admin/agent-changes/'.$id.'/approve')->assertRedirect();
        $this->actingAs($this->admin())->post('/admin/agent-changes/'.$id.'/apply')->assertRedirect();
        $group = ServicePackage::where('name_th', 'COCO PARC')->firstOrFail();
        $variant = ServicePackage::where('name_th', 'Studio 25.5–27 sqm')->firstOrFail();
        $offer = ServicePackage::where('name_th', 'Draft offer requiring availability confirmation')->firstOrFail();
        $this->assertSame('group', $group->record_kind);
        $this->assertSame($group->id, $variant->parent_id);
        $this->assertSame($variant->id, $offer->parent_id);
        $this->assertFalse($offer->is_published);
        $this->assertSame('unknown', $offer->availability);
        $this->assertDatabaseHas('record_sources', ['entity_type' => 'catalog', 'entity_id' => $group->id, 'verified' => false]);
        $this->assertDatabaseCount('record_revisions', 3);
    }

    public function test_bulk_apply_rolls_back_every_selected_change_set_when_one_has_a_conflict(): void
    {
        $item = ServicePackage::factory()->create(['lock_version' => 1, 'name_th' => 'Before']);
        ['plainText' => $plain] = $this->token();

        $create = $this->catalogProposal();
        $create['operations'][0]['payload']['name_th'] = 'Must not be created by a failed bulk apply';
        $createId = $this->postJson('/api/v1/agent/changes', $create, $this->headers($plain, 'bulk-create'))->json('data.id');

        $update = ['operations' => [[
            'entity' => 'catalog',
            'action' => 'update',
            'target_id' => $item->id,
            'expected_version' => 1,
            'payload' => ['name_th' => 'Agent edit'],
        ]]];
        $updateId = $this->postJson('/api/v1/agent/changes', $update, $this->headers($plain, 'bulk-conflict'))->json('data.id');
        $item->forceFill(['name_th' => 'Human edit', 'lock_version' => 2])->save();

        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/agent-changes/'.$createId.'/approve')->assertRedirect();
        $this->actingAs($admin)->post('/admin/agent-changes/'.$updateId.'/approve')->assertRedirect();

        $this->actingAs($admin)
            ->post('/admin/agent-changes/bulk-apply', ['change_sets' => [$createId, $updateId]])
            ->assertSessionHas('error');

        $this->assertSame('Human edit', $item->fresh()->name_th);
        $this->assertDatabaseMissing('packages', ['name_th' => 'Must not be created by a failed bulk apply']);
        $this->assertDatabaseHas('agent_change_sets', ['id' => $createId, 'status' => 'approved']);
        $this->assertDatabaseHas('agent_change_sets', ['id' => $updateId, 'status' => 'conflicted']);
    }

    public function test_bulk_apply_marks_every_selected_approved_change_set_as_applied_and_keeps_creates_as_drafts(): void
    {
        ['plainText' => $plain] = $this->token();

        $first = $this->catalogProposal();
        $first['operations'][0]['payload']['name_th'] = 'First approved bulk proposal';
        $firstId = $this->postJson('/api/v1/agent/changes', $first, $this->headers($plain, 'bulk-first'))->json('data.id');

        $second = $this->catalogProposal();
        $second['operations'][0]['payload']['name_th'] = 'Second approved bulk proposal';
        $secondId = $this->postJson('/api/v1/agent/changes', $second, $this->headers($plain, 'bulk-second'))->json('data.id');

        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/agent-changes/'.$firstId.'/approve')->assertRedirect();
        $this->actingAs($admin)->post('/admin/agent-changes/'.$secondId.'/approve')->assertRedirect();

        $this->actingAs($admin)
            ->post('/admin/agent-changes/bulk-apply', ['change_sets' => [$firstId, $secondId]])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('agent_change_sets', ['id' => $firstId, 'status' => 'applied']);
        $this->assertDatabaseHas('agent_change_sets', ['id' => $secondId, 'status' => 'applied']);
        $this->assertDatabaseCount('packages', 6);
        $this->assertSame(0, ServicePackage::query()->where('is_published', true)->count());
    }

    public function test_apply_revalidates_lock_versions_and_rolls_back_all_operations(): void
    {
        $item = ServicePackage::factory()->create(['lock_version' => 1, 'name_th' => 'Before']);
        ['plainText' => $plain] = $this->token();
        $proposal = ['operations' => [
            ['entity' => 'catalog', 'action' => 'update', 'target_id' => $item->id, 'expected_version' => 1, 'payload' => ['name_th' => 'Agent edit']],
            ['entity' => 'catalog', 'action' => 'create', 'client_ref' => 'new', 'payload' => ['name_th' => 'Must not exist']],
        ]];
        $id = $this->postJson('/api/v1/agent/changes', $proposal, $this->headers($plain))->json('data.id');
        $item->forceFill(['name_th' => 'Human edit', 'lock_version' => 2])->save();
        $this->actingAs($this->admin())->post('/admin/agent-changes/'.$id.'/approve')->assertRedirect();
        $this->actingAs($this->admin())->post('/admin/agent-changes/'.$id.'/apply')->assertSessionHas('error');
        $this->assertSame('Human edit', $item->fresh()->name_th);
        $this->assertDatabaseMissing('packages', ['name_th' => 'Must not exist']);
        $this->assertDatabaseHas('agent_change_sets', ['id' => $id, 'status' => 'conflicted']);
    }

    public function test_token_revocation_suspends_pending_change_but_never_rolls_back_applied_data(): void
    {
        ['token' => $token, 'plainText' => $plain] = $this->token();
        $id = $this->postJson('/api/v1/agent/changes', $this->catalogProposal(), $this->headers($plain))->json('data.id');
        $token->update(['revoked_at' => now()]);
        $this->assertDatabaseHas('agent_change_sets', ['id' => $id, 'status' => 'suspended']);
        $this->actingAs($this->admin())->post('/admin/agent-changes/'.$id.'/approve')->assertSessionHas('error');
    }

    public function test_faq_and_knowledge_are_separate_adapters_and_apply_inactive_drafts(): void
    {
        ['plainText' => $plain] = $this->token(['agent:read', 'changes:write', 'agent:faq', 'agent:knowledge']);
        $proposal = ['operations' => [
            ['entity' => 'faq', 'action' => 'create', 'client_ref' => 'faq-1', 'payload' => ['question_th' => 'มีที่จอดรถไหม', 'answer_th' => 'รอตรวจสอบจากนิติบุคคล']],
            ['entity' => 'knowledge', 'action' => 'create', 'client_ref' => 'kb-1', 'payload' => ['title' => 'Project fact', 'body' => 'ข้อมูลจากเอกสาร รอตรวจทาน', 'type' => 'reference']],
        ]];
        $id = $this->postJson('/api/v1/agent/changes', $proposal, $this->headers($plain))->json('data.id');
        $this->actingAs($this->admin())->post('/admin/agent-changes/'.$id.'/approve');
        $this->actingAs($this->admin())->post('/admin/agent-changes/'.$id.'/apply');
        $this->assertFalse(Faq::firstOrFail()->is_active);
        $this->assertFalse(KnowledgeEntry::firstOrFail()->is_active);
    }

    public function test_verified_document_source_requires_private_document_id_not_an_agent_path(): void
    {
        $issuer = $this->admin();
        ['token' => $token, 'plainText' => $plain] = $this->token();
        $token->forceFill(['user_id' => $issuer->id])->save();
        $document = DocumentSource::create([
            'user_id' => $issuer->id, 'source_type' => 'upload', 'original_filename' => 'synthetic.pdf', 'storage_disk' => 'local',
            'storage_path' => 'private/synthetic.pdf', 'file_hash' => hash('sha256', 'synthetic'),
            'mime_type' => 'application/pdf', 'file_size' => 100, 'status' => 'ready',
        ]);
        $proposal = $this->catalogProposal();
        $proposal['operations'][0]['sources'] = [['document_id' => $document->id, 'page' => 13, 'field_paths' => ['name_th']]];
        $id = $this->postJson('/api/v1/agent/changes', $proposal, $this->headers($plain))->json('data.id');
        $this->actingAs($this->admin())->post('/admin/agent-changes/'.$id.'/approve');
        $this->actingAs($this->admin())->post('/admin/agent-changes/'.$id.'/apply');
        $this->assertDatabaseHas('record_sources', ['document_source_id' => $document->id, 'verified' => true]);
    }

    public function test_agent_cannot_claim_another_staff_members_document_as_verified_evidence(): void
    {
        $issuer = $this->admin();
        $otherUser = User::factory()->create();
        ['token' => $token, 'plainText' => $plain] = $this->token();
        $token->forceFill(['user_id' => $issuer->id])->save();
        $document = DocumentSource::create([
            'user_id' => $otherUser->id, 'source_type' => 'upload', 'original_filename' => 'other-user.pdf', 'storage_disk' => 'local',
            'storage_path' => 'private/other-user.pdf', 'file_hash' => hash('sha256', 'other-user'),
            'mime_type' => 'application/pdf', 'file_size' => 100, 'status' => 'ready',
        ]);
        $proposal = $this->catalogProposal();
        $proposal['operations'][0]['sources'] = [['document_id' => $document->id, 'page' => 1]];

        $this->postJson('/api/v1/agent/changes/preview', $proposal, $this->headers($plain))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('operations.0.sources.0.document_id');
        $this->assertDatabaseCount('agent_change_sets', 0);
    }
}
