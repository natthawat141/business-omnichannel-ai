<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_requires_admin(): void
    {
        $this->get('/admin/ai-setup')->assertRedirect('/login');
        $this->postJson('/admin/ai-setup/keys', ['client' => 'cli', 'minutes' => 60])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/admin/ai-setup')->assertForbidden();
        $this->postJson('/admin/ai-setup/keys', ['client' => 'cli', 'minutes' => 60])->assertForbidden();
    }

    public function test_key_is_scoped_hashed_and_expires_at_selected_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $response = $this->postJson('/admin/ai-setup/keys', ['client' => 'codex', 'minutes' => 60, 'abilities' => ['*']])->assertCreated();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $plain = $response->json('key');
        $token = ApiToken::findOrFail($response->json('id'));
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertSame(['documents:read'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addHour()));
        $this->withToken($plain)->getJson('/api/v1/documents')->assertOk();
        $this->withToken($plain)->getJson('/api/v1/packages')->assertForbidden();
        $this->get('/admin/ai-setup')->assertOk()->assertDontSee($plain);
        $this->assertFalse(in_array($plain, session()->all(), true));
        $this->travel(61)->minutes();
        $this->withToken($plain)->getJson('/api/v1/documents')->assertUnauthorized();
    }

    public function test_admin_can_issue_an_expiring_opt_in_catalog_proposal_key(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $response = $this->postJson('/admin/ai-setup/keys', [
            'client' => 'claude-code',
            'minutes' => 15,
            'scope' => 'catalog_proposals',
            'proposal_entities' => ['catalog'],
        ])->assertCreated();

        $token = ApiToken::findOrFail($response->json('id'));
        $this->assertSame(['agent:read', 'changes:write', 'agent:catalog'], $token->abilities);
        $this->withToken($response->json('key'))->getJson('/api/v1/agent/schema')->assertOk();
        $this->withToken($response->json('key'))->getJson('/api/v1/documents')->assertForbidden();
        $this->withToken($response->json('key'))->postJson('/api/v1/agent/changes', [])->assertUnprocessable();
    }

    public function test_revoke_disables_key_without_touching_system_token(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $response = $this->postJson('/admin/ai-setup/keys', ['client' => 'cli', 'minutes' => 15])->assertCreated();
        $this->deleteJson('/admin/ai-setup/keys/'.$response->json('id'))->assertOk();
        $this->withToken($response->json('key'))->getJson('/api/v1/documents')->assertUnauthorized();
        $system = ApiToken::issue('AI Setup: protected', ['read'], null, true)['token'];
        $this->deleteJson('/admin/ai-setup/keys/'.$system->id)->assertNotFound();
        $this->assertNull($system->fresh()->revoked_at);
        $other = ApiToken::issue('another-integration')['token'];
        $this->deleteJson('/admin/ai-setup/keys/'.$other->id)->assertNotFound();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->deleteJson('/admin/ai-setup/keys/'.$response->json('id'))->assertForbidden();
    }

    public function test_invalid_lifetimes_and_unsupported_clients_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([0, 1, 1440, null] as $minutes) {
            $this->postJson('/admin/ai-setup/keys', ['client' => 'cli', 'minutes' => $minutes])->assertUnprocessable();
        }
        $this->postJson('/admin/ai-setup/keys', ['client' => 'cowork', 'minutes' => 60])->assertUnprocessable();
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_issuance_is_rate_limited(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/admin/ai-setup/keys', ['client' => 'cli', 'minutes' => 240])->assertCreated();
        }
        $this->postJson('/admin/ai-setup/keys', ['client' => 'cli', 'minutes' => 240])->assertTooManyRequests();
        $this->assertDatabaseCount('api_tokens', 5);
    }
}
