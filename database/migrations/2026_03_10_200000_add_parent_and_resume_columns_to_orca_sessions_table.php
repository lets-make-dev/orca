<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('orca_sessions', 'parent_id')) {
                $table->ulid('parent_id')->nullable()->after('source_url');
            }
            if (! Schema::hasColumn('orca_sessions', 'resume_session_id')) {
                $table->string('resume_session_id')->nullable()->after('claude_session_id');
            }
            if (! Schema::hasColumn('orca_sessions', 'skip_permissions')) {
                $table->boolean('skip_permissions')->default(false)->after('permission_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            $table->dropColumn(['parent_id', 'resume_session_id', 'skip_permissions']);
        });
    }
};
