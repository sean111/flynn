<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_records', function (Blueprint $table) {
            $table->string('scope_hash', 64)->nullable()->after('request_hash');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_records', function (Blueprint $table) {
            $table->dropColumn('scope_hash');
        });
    }
};
