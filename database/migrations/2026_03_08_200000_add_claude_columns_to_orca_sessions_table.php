<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            $table->string('session_type')->default('command')->after('id');
            $table->string('command')->nullable()->change();
            $table->text('prompt')->nullable()->after('command');
            $table->string('claude_session_id')->nullable()->after('prompt');
            $table->string('permission_mode')->nullable()->after('claude_session_id');
            $table->json('allowed_tools')->nullable()->after('permission_mode');
            $table->string('working_directory')->nullable()->after('allowed_tools');
            $table->integer('max_turns')->nullable()->after('working_directory');
        });
    }

    public function down(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'session_type',
                'prompt',
                'claude_session_id',
                'permission_mode',
                'allowed_tools',
                'working_directory',
                'max_turns',
            ]);
        });
    }
};
