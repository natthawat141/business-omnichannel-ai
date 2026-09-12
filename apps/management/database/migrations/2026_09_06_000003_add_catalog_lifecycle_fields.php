<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('record_kind', 16)->default('offer')->after('item_type');
            $table->foreignId('parent_id')->nullable()->after('category_id')->constrained('packages')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1)->after('attributes');
            $table->timestamp('archived_at')->nullable()->after('effective_until');
            $table->index(['record_kind', 'archived_at'], 'packages_kind_archive_idx');
            $table->index('parent_id', 'packages_parent_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only data safeguard: do not drop catalog lifecycle fields.');
    }
};
