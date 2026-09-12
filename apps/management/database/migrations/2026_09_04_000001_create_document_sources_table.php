<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 32)->default('upload'); // upload | google_drive
            $table->string('original_filename', 255);
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path', 500);
            $table->string('file_hash', 64)->index(); // SHA-256
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size'); // bytes
            $table->unsignedInteger('page_count')->nullable();
            $table->string('status', 32)->default('uploaded')->index(); // uploaded | extracting | ready | ocr_required | failed | cancelled
            $table->string('failure_reason', 100)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sources');
    }
};
