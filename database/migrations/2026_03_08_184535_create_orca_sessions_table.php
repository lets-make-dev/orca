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
        Schema::create('orca_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('command');
            $table->string('status')->default('pending');
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->integer('pid')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orca_sessions');
    }
};
