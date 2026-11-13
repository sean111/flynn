<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('desktop_authorization_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('code_challenge', 128);
            $table->string('state_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('exchanged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_authorization_codes');
        Schema::dropIfExists('service_credentials');
    }
};
