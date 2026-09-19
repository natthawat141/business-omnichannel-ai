<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_provider', 32)->default('password')->after('email');
            $table->string('approval_status', 32)->default('approved')->after('role');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['auth_provider', 'approval_status']);
        });
    }
};
