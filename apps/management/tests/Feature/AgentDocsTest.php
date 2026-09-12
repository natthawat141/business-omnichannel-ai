<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\AgentSetupExamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_docs_are_public_readable_html_with_no_account_data(): void
    {
        config(['app.url' => 'https://management.example.test']);
        $this->get('/docs/agent-setup')->assertOk()
            ->assertSee('Aion3 Docs')->assertSee('id="antigravity"', false)
            ->assertSee('PASTE_KEY_LOCALLY')->assertSee('https://management.example.test/downloads/');
        $this->actingAs(User::factory()->create(['email' => 'private-docs-test@example.test', 'is_admin' => true]));
        $plain = ApiToken::issue('private-docs-test', ['documents:read'])['plainText'];
        $this->get('/docs/agent-setup')->assertOk()->assertDontSee($plain)
            ->assertDontSee('private-docs-test@example.test')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_antigravity_template_has_no_real_credentials_and_valid_stdio_schema(): void
    {
        $json = json_decode(AgentSetupExamples::antigravity('https://management.example.test'), true, flags: JSON_THROW_ON_ERROR);
        $server = $json['mcpServers']['document-intake'];
        $this->assertSame('/ABSOLUTE/PATH/TO/node', $server['command']);
        $this->assertStringEndsWith('/bin/document-intake-mcp.js', $server['args'][0]);
        $this->assertSame('PASTE_KEY_LOCALLY', $server['env']['MANAGEMENT_API_TOKEN']);
        $this->assertSame('https://management.example.test', $server['env']['MANAGEMENT_API_BASE_URL']);
        $this->assertArrayNotHasKey('serverUrl', $server);
    }

    public function test_antigravity_key_keeps_scope_expiry_and_revocation(): void
    {
        $this->postJson('/admin/ai-setup/keys', ['client' => 'antigravity', 'minutes' => 60])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $result = $this->postJson('/admin/ai-setup/keys', ['client' => 'antigravity', 'minutes' => 60])->assertCreated();
        $token = ApiToken::findOrFail($result->json('id'));
        $this->assertSame(['documents:read'], $token->abilities);
        $this->assertSame(hash('sha256', $result->json('key')), $token->token_hash);
        $this->assertTrue($token->expires_at->greaterThan(now()));
        $this->assertTrue($token->expires_at->lessThanOrEqualTo(now()->addHour()));
        $this->withToken($result->json('key'))->getJson('/api/v1/documents')->assertOk();
        $this->withToken($result->json('key'))->getJson('/api/v1/packages')->assertForbidden();
        $this->deleteJson('/admin/ai-setup/keys/'.$token->id)->assertOk();
        $this->withToken($result->json('key'))->getJson('/api/v1/documents')->assertUnauthorized();
    }
}
