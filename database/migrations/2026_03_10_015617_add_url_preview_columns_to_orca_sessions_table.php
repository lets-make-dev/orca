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
            $table->unsignedBigInteger('user_id')->nullable()->after('source_url');
            $table->string('user_email')->nullable()->after('user_id');
            $table->string('route_handler', 500)->nullable()->after('user_email');
            $table->string('route_handler_type', 50)->nullable()->after('route_handler');
            $table->string('route_name')->nullable()->after('route_handler_type');
        });
    }

    public function down(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'user_email', 'route_handler', 'route_handler_type', 'route_name']);
        });
    }
};
