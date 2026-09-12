<?php

namespace App\Services\Agent;

use App\Models\ApiToken;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Token;

class McpConnections
{
    public const SCOPES = [
        'mcp:use' => 'อ่านชื่อและสถานะเอกสาร (ไม่อ่านเนื้อหา PDF)',
        'agent:read' => 'อ่านโครงสร้างและข้อมูลสำหรับเตรียมข้อเสนอ',
        'agent:catalog' => 'เข้าถึงรายการสินค้า / ทรัพย์',
        'agent:faq' => 'เข้าถึงคำถามพบบ่อย',
        'agent:knowledge' => 'เข้าถึงคลังความรู้',
        'changes:write' => 'ส่งข้อเสนอแก้ไขให้คนตรวจ (ไม่บันทึกลงข้อมูลจริง)',
    ];

    public static function endpoint(): string
    {
        return rtrim(config('app.url'), '/').'/mcp';
    }

    public function issued(AccessTokenCreated $event): void
    {
        $token = Token::findOrFail($event->tokenId);
        $user = User::whereKey($event->userId)->lockForUpdate()->first();
        abort_unless($user?->is_active && $user->is_admin, 403);
        $scopes = $token->scopes;
        sort($scopes);
        abort_unless(in_array('mcp:use', $scopes, true) && ! array_diff($scopes, array_keys(self::SCOPES)), 403);
        $hash = hash('sha256', json_encode($scopes));
        $connection = McpConnection::where('user_id', $user->id)->where('client_id', $event->clientId)
            ->where('scope_hash', $hash)->where('session_version', $user->session_version)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->lockForUpdate()->first();
        if (! $connection) {
            abort_unless(request('grant_type') === 'authorization_code', 403, 'Reconnect in your AI app.');
            // Internal proposal identity, never an issued or accepted bearer credential.
            $principal = ApiToken::create([
                'name' => 'MCP OAuth principal', 'prefix' => 'oauth',
                'token_hash' => hash('sha256', random_bytes(64)),
                'abilities' => array_values(array_unique(['documents:read', ...array_diff($scopes, ['mcp:use'])])),
                'expires_at' => now()->addDays(7),
            ]);
            $principal->forceFill(['user_id' => $user->id])->save();
            $connection = McpConnection::create([
                'user_id' => $user->id, 'client_id' => $event->clientId,
                'api_token_id' => $principal->id, 'session_version' => $user->session_version,
                'scope_hash' => $hash, 'scopes' => $scopes, 'expires_at' => $principal->expires_at,
            ]);
        }
        $token->forceFill(['mcp_connection_id' => $connection->id])->save();
    }

    public function revoke(McpConnection $connection): void
    {
        DB::transaction(function () use ($connection) {
            User::orderBy('id')->lockForUpdate()->first();
            User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $connection->refresh();
            $connection->update(['revoked_at' => now()]);
            $ids = DB::table('oauth_access_tokens')->where('mcp_connection_id', $connection->id)->select('id');
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);
            DB::table('oauth_access_tokens')->where('mcp_connection_id', $connection->id)->update(['revoked' => true]);
            DB::table('oauth_auth_codes')->where('user_id', $connection->user_id)
                ->where('client_id', $connection->client_id)->update(['revoked' => true]);
            ApiToken::findOrFail($connection->api_token_id)->update(['revoked_at' => now()]);
            \App\Models\AgentChangeSet::where('api_token_id', $connection->api_token_id)
                ->whereIn('status', ['proposed', 'approved'])
                ->update(['status' => 'suspended', 'suspended_at' => now()]);
        });
    }
}
