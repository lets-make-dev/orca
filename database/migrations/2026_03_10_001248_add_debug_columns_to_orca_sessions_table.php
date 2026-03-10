<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            $table->string('screenshot_path')->nullable()->after('prompt');
            $table->string('source_url')->nullable()->after('screenshot_path');
        });
    }

    public function down(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            $table->dropColumn(['screenshot_path', 'source_url']);
        });
    }
};
