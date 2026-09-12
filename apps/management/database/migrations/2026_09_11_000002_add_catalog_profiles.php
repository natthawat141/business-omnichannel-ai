<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('profile', 60)->nullable()->index();
            $table->json('profile_data')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve catalog profile data. Roll back code without dropping these columns.');
    }
};
