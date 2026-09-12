<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_categories', function (Blueprint $table) {
            $table->unsignedInteger('schema_version')->default(1);
        });
    }

    public function down(): void
    {
        // A code rollback must retain the schema discriminator and typed data.
        throw new RuntimeException('Forward-only data safeguard: retain schema_version; roll back application code instead.');
    }
};
