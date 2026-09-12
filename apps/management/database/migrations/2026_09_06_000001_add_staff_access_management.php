<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('session_version')->default(0);
        });
        Schema::table('api_tokens', fn (Blueprint $table) => $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete());
        Schema::create('user_management_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('target_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 40);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_management_events');
        Schema::table('api_tokens', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'is_active', 'session_version']));
    }
};
