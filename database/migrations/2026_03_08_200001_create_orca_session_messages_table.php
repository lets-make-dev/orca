<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orca_session_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')
                ->constrained('orca_sessions')
                ->cascadeOnDelete();
            $table->string('direction');
            $table->string('type');
            $table->json('content');
            $table->json('metadata')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orca_session_messages');
    }
};
