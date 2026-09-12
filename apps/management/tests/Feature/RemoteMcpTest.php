<?php

namespace Tests\Feature;

use App\Models\McpConnection;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Agent\McpConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

class RemoteMcpTest extends TestCase
{
    use RefreshDatabase;

    private static string $keyDirectory;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$keyDirectory = sys_get_temp_dir().'/management-oauth-test-'.bin2hex(random_bytes(8));
        mkdir(self::$keyDirectory, 0700);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        file_put_contents(self::$keyDirectory.'/oauth-private.key', $private);
        chmod(self::$keyDirectory.'/oauth-private.key', 0600);
        file_put_contents(self::$keyDirectory.'/oauth-public.key', openssl_pkey_get_details($key)['key']);
        chmod(self::$keyDirectory.'/oauth-public.key', 0600);
    }

    public static function tearDownAfterClass(): void
    {
        unlink(self::$keyDirectory.'/oauth-private.key');
        unlink(self::$keyDirectory.'/oauth-public.key');
        rmdir(self::$keyDirectory);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['remote-mcp.enabled' => true]);
        Passport::loadKeysFrom(self::$keyDirectory);
    }

    private function registration(): string
    {
        return $this->postJson('/oauth/register', ['client_name' => 'Synthetic test client',
            'redirect_uris' => ['http://127.0.0.1:32123/callback'], 'token_endpoint_auth_method' => 'none'])
            ->assertCreated()->json('client_id');
    }

    private function authorize(string $client, User $user, string $scope = 'mcp:use'): array
    {
        $verifier = str_repeat('v', 64);
        $params = ['response_type' => 'code', 'client_id' => $client, 'redirect_uri' => 'http://127.0.0.1:32123/callback',
            'scope' => $scope, 'state' => 'synthetic-state', 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'resource' => McpConnections::endpoint()];
        $this->actingAs($user, 'web')->withSession(['staff_version' => $user->session_version]);
        $this->get('/oauth/authorize?'.http_build_query($params))->assertOk()->assertSee('อนุญาตและกลับไปที่แอป');
        $response = $this->post('/oauth/authorize', ['client_id' => $client, 'state' => 'synthetic-state',
            'auth_token' => session('authToken')])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $callback);
        $this->assertSame('synthetic-state', $callback['state']);
        return ['grant_type' => 'authorization_code', 'client_id' => $client, 'code' => $callback['code'],
            'code_verifier' => $verifier, 'redirect_uri' => $params['redirect_uri'], 'resource' => $params['resource']];
    }

    private function connect(string $scope = 'mcp:use'): array
    {
        $client = $this->registration();
        $user = User::factory()->create(['is_admin' => true]);
        $body = $this->authorize($client, $user, $scope);
        $tokens = $this->postJson('/oauth/token', $body)->assertOk()->json();
        return [$tokens, $client, $user];
    }

    private function rpc(?string $token, string $method, array $params = [])
    {
        $this->app['auth']->forgetGuards();
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            ['Accept' => 'application/json, text/event-stream', 'Authorization' => $token ? 'Bearer '.$token : '']);
    }

    public function test_feature_gate_and_discovery_never_expose_data(): void
    {
        config(['remote-mcp.enabled' => false]);
        $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->rpc(null, 'tools/list')->assertNotFound();
        config(['remote-mcp.enabled' => true]);
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('resource', McpConnections::endpoint());
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('code_challenge_methods_supported.0', 'S256');
        $this->rpc(null, 'tools/list')->assertUnauthorized()->assertHeader('WWW-Authenticate');
    }

    public function test_registration_rejects_host_tricks_and_unsupported_flows(): void
    {
        foreach (['https://chatgpt.com.evil.test/cb', 'https://chatgpt.com@evil.test/cb', 'http://example.test/cb',
            'https://chatgpt.com/cb#fragment', 'javascript:alert(1)'] as $uri) {
            $this->postJson('/oauth/register', ['client_name' => 'test', 'redirect_uris' => [$uri]])->assertUnprocessable();
        }
        $this->postJson('/oauth/token', ['grant_type' => 'password'])->assertStatus(400);
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'resource' => 'https://wrong.test/mcp'])->assertStatus(400);
    }

    public function test_login_consent_pkce_single_use_and_read_only_tools(): void
    {
        $client = $this->registration();
        $this->get('/oauth/authorize?client_id='.$client)->assertStatus(400);
        $user = User::factory()->create(['is_admin' => true]);
        $body = $this->authorize($client, $user);
        $wrong = $body; $wrong['code_verifier'] = str_repeat('x', 64);
        $this->postJson('/oauth/token', $wrong)->assertStatus(400);
        // A failed verifier can consume the code: obtain a fresh consent for success.
        $body = $this->authorize($client, $user);
        $response = $this->postJson('/oauth/token', $body)->assertOk()->assertHeader('Cache-Control');
        $tokens = $response->json();
        $this->assertLessThanOrEqual(1800, $tokens['expires_in']);
        $this->postJson('/oauth/token', $body)->assertStatus(400);
        $this->rpc($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'synthetic', 'version' => '1']])->assertOk()->assertJsonPath('result.serverInfo.name', 'Management');
        $list = $this->rpc($tokens['access_token'], 'tools/list')->assertOk();
        $this->assertEqualsCanonicalizing(['document_list', 'document_get'], array_column($list->json('result.tools'), 'name'));
        $read = $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'document_list', 'arguments' => ['limit' => 1]])->assertOk();
        $this->assertFalse($read->json('result.isError') ?? false);
        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'agent_changes_submit', 'arguments' => []])->assertOk()->assertJsonStructure(['error']);
        $this->withToken($tokens['access_token'])->getJson('/api/v1/documents')->assertUnauthorized();
        $this->assertDatabaseCount('packages', 0);
    }

    public function test_refresh_retains_proposal_identity_and_disconnect_stops_access(): void
    {
        [$tokens, $client, $user] = $this->connect('mcp:use agent:read agent:catalog changes:write');
        $args = ['operations' => [['entity' => 'catalog', 'action' => 'create', 'client_ref' => 'example',
            'payload' => ['name_th' => 'Synthetic draft']]], 'idempotency_key' => 'oauth-proposal-001'];
        $result = $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'agent_changes_submit', 'arguments' => $args])->assertOk();
        $this->assertFalse($result->json('result.isError') ?? false);
        $id = json_decode($result->json('result.content.0.text'), true)['data']['id'];
        $this->assertDatabaseCount('packages', 0);
        $connection = McpConnection::firstOrFail();
        $next = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client,
            'refresh_token' => $tokens['refresh_token']])->assertOk()->json();
        $this->assertDatabaseCount('mcp_connections', 1);
        $this->rpc($next['access_token'], 'tools/call', ['name' => 'agent_changes_submit', 'arguments' => $args])->assertOk();
        $this->assertDatabaseCount('agent_change_sets', 1);
        $this->rpc($next['access_token'], 'tools/call', ['name' => 'agent_changes_get', 'arguments' => ['id' => $id]])->assertOk();
        $other = User::factory()->create(['is_admin' => true]);
        $this->actingAs($other, 'web')->deleteJson('/admin/ai-setup/connections/'.$connection->id)->assertNotFound();
        $this->actingAs($user, 'web')->deleteJson('/admin/ai-setup/connections/'.$connection->id)->assertOk();
        $this->rpc($next['access_token'], 'tools/list')->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client,
            'refresh_token' => $next['refresh_token']])->assertStatus(400);
        $this->assertDatabaseHas('agent_change_sets', ['id' => $id, 'status' => 'suspended']);
    }

    public function test_remote_mcp_exposes_and_executes_exact_profile_filters(): void
    {
        [$tokens] = $this->connect('mcp:use agent:read agent:catalog');
        ServicePackage::create(['name_th' => 'Project with pool', 'record_kind' => 'group', 'profile' => 'property_project',
            'profile_data' => ['facilities' => ['pool']]]);
        ServicePackage::create(['name_th' => 'Project with gym', 'record_kind' => 'group', 'profile' => 'property_project',
            'profile_data' => ['facilities' => ['gym']]]);
        $tools = $this->rpc($tokens['access_token'], 'tools/list')->assertOk()->json('result.tools');
        $tool = collect($tools)->firstWhere('name', 'agent_records_search');
        $this->assertArrayHasKey('facility', $tool['inputSchema']['properties']);
        $response = $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'agent_records_search',
            'arguments' => ['entity' => 'catalog', 'profile' => 'property_project', 'facility' => 'pool']])->assertOk();
        $this->assertFalse($response->json('result.isError') ?? false);
        $items = json_decode($response->json('result.content.0.text'), true)['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Project with pool', $items[0]['name_th']);
        $this->assertSame(['pool'], $items[0]['profile_data']['facilities']);
    }

    public function test_account_change_expiry_and_validation_are_enforced(): void
    {
        [$tokens, $client, $user] = $this->connect('mcp:use agent:read agent:catalog changes:write');
        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'agent_records_search', 'arguments' => ['entity' => 'catalog', 'limit' => 51]])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'agent_records_search', 'arguments' => ['entity' => 'faq']])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'agent_changes_preview', 'arguments' => ['operations' => [[
            'entity' => 'catalog', 'action' => 'create', 'payload' => ['name_th' => 'no', 'is_published' => true],
        ]]]])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('packages', 0);
        $user->update(['is_active' => false]);
        $this->rpc($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $user->update(['is_active' => true]);
        $this->rpc($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client,
            'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
    }

    public function test_guest_login_returns_to_consent_and_deny_creates_no_connection(): void
    {
        $client = $this->registration();
        $query = http_build_query(['client_id' => $client, 'response_type' => 'code',
            'redirect_uri' => 'http://127.0.0.1:32123/callback', 'scope' => 'mcp:use',
            'state' => 'login-state', 'code_challenge_method' => 'S256', 'code_challenge' => str_repeat('a', 43)]);
        $this->get('/oauth/authorize?'.$query)->assertRedirect('/login');
        $user = User::factory()->create(['is_admin' => true, 'password' => 'synthetic-test-password']);
        $login = $this->post('/login', ['email' => $user->email, 'password' => 'synthetic-test-password'], ['X-Inertia' => 'true'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location');
        $url = $login->headers->get('X-Inertia-Location');
        $this->assertStringContainsString('/oauth/authorize?', $url);
        $this->get($url)->assertOk()->assertHeader('X-Frame-Options', 'DENY');
        $this->assertDatabaseCount('mcp_connections', 0);
        $response = $this->delete('/oauth/authorize', ['client_id' => $client, 'state' => 'login-state', 'auth_token' => session('authToken')])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $result);
        $this->assertSame('access_denied', $result['error']);
        $this->assertArrayNotHasKey('code', $result);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_expired_connections_cannot_be_extended_by_refresh(): void
    {
        [$tokens, $client] = $this->connect();
        DB::table('oauth_access_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->rpc($tokens['access_token'], 'tools/list')->assertUnauthorized();
        McpConnection::query()->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client,
            'refresh_token' => $tokens['refresh_token']])->assertForbidden();
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('mcp_connections', 1);
    }

    public function test_consent_requires_csrf_and_active_admin(): void
    {
        $client = $this->registration();
        $user = User::factory()->create(['is_admin' => false]);
        $query = http_build_query(['client_id' => $client, 'response_type' => 'code',
            'redirect_uri' => 'http://127.0.0.1:32123/callback', 'scope' => 'mcp:use',
            'code_challenge_method' => 'S256', 'code_challenge' => str_repeat('a', 43)]);
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.$query)->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin, 'web')->get('/oauth/authorize?'.$query)->assertOk();
        // Enable the actual CSRF check which Laravel normally skips in tests.
        $this->app->instance('env', 'local');
        $this->post('/oauth/authorize', ['client_id' => $client, 'auth_token' => session('authToken')])->assertStatus(419);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_simple_page_advanced_fallback_and_docs_are_linked(): void
    {
        $this->get('/docs/remote-mcp')->assertOk()->assertSee('Copy page')->assertSee('/admin/ai-setup', false);
        $this->get('/docs/agent-setup')->assertOk()->assertSee('/docs/remote-mcp', false);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/ai-setup')->assertInertia(fn ($page) => $page->component('AiConnect')->where('remoteEnabled', true));
        $this->get('/admin/ai-setup?advanced=1')->assertInertia(fn ($page) => $page->component('AiSetup'));
    }
}
