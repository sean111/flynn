<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }
};
