<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->unsignedBigInteger('lock_version')->default(1)->after('is_active');
            $table->timestamp('archived_at')->nullable()->after('lock_version');
            $table->index('archived_at', 'faqs_archived_idx');
        });
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('lock_version')->default(1)->after('version');
            $table->timestamp('archived_at')->nullable()->after('lock_version');
            $table->index('archived_at', 'knowledge_archived_idx');
        });
        Schema::create('agent_change_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('api_token_id')->constrained('api_tokens')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('proposed');
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64);
            $table->json('schema_versions');
            $table->unsignedTinyInteger('operation_count');
            $table->string('review_note', 1000)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->unique(['api_token_id', 'idempotency_key'], 'agent_changeset_idempotency_unique');
            $table->index(['status', 'created_at']);
        });
        Schema::create('agent_change_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('change_set_id');
            $table->unsignedTinyInteger('sequence');
            $table->string('entity_type', 20);
            $table->string('action', 20);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('client_ref', 60)->nullable();
            $table->string('parent_client_ref', 60)->nullable();
            $table->unsignedBigInteger('expected_version')->nullable();
            $table->json('payload');
            $table->json('sources')->nullable();
            $table->json('preview')->nullable();
            $table->timestamps();
            $table->foreign('change_set_id')->references('id')->on('agent_change_sets')->cascadeOnDelete();
            $table->unique(['change_set_id', 'sequence']);
            $table->unique(['change_set_id', 'client_ref']);
        });
        Schema::create('record_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 20);
            $table->unsignedBigInteger('entity_id');
            $table->uuid('change_set_id')->nullable();
            $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->unsignedBigInteger('lock_version');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at');
            $table->foreign('change_set_id')->references('id')->on('agent_change_sets')->nullOnDelete();
            $table->index(['entity_type', 'entity_id', 'created_at'], 'record_revisions_entity_idx');
        });
        Schema::create('record_sources', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 20);
            $table->unsignedBigInteger('entity_id');
            $table->uuid('change_set_id')->nullable();
            $table->foreignId('document_source_id')->nullable()->constrained('document_sources')->nullOnDelete();
            $table->string('external_label', 255)->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->json('field_paths')->nullable();
            $table->boolean('verified')->default(false);
            $table->timestamp('created_at');
            $table->foreign('change_set_id')->references('id')->on('agent_change_sets')->nullOnDelete();
            $table->index(['entity_type', 'entity_id'], 'record_sources_entity_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only data safeguard: preserve agent proposal and revision history.');
    }
};
