<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->string('recipient_id', 64)->change();
        });
        Schema::create('catalog_import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('game_flavor', 20);
            $table->string('checksum', 64);
            $table->string('source_name', 255);
            $table->string('status', 16)->default('processing');
            $table->unsignedInteger('professions_imported')->default(0);
            $table->unsignedInteger('spells_imported')->default(0);
            $table->unsignedInteger('items_imported')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['game_flavor', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_runs');
    }
};
