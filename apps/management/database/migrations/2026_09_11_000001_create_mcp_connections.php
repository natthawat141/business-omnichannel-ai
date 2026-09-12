<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('client_id', 100)->index();
            $table->foreignId('api_token_id')->constrained();
            $table->unsignedInteger('session_version');
            $table->string('scope_hash', 64);
            $table->json('scopes');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'client_id', 'scope_hash']);
        });
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->foreignId('mcp_connection_id')->nullable()->constrained('mcp_connections');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_access_tokens', fn (Blueprint $table) => $table->dropConstrainedForeignId('mcp_connection_id'));
        Schema::dropIfExists('mcp_connections');
    }
};
