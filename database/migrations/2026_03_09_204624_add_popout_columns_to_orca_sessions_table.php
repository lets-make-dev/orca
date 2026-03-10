<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            $table->timestamp('popped_out_at')->nullable()->after('completed_at');
            $table->longText('popout_transcript')->nullable()->after('popped_out_at');
            $table->string('popout_script_path')->nullable()->after('popout_transcript');
        });
    }

    public function down(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            $table->dropColumn(['popped_out_at', 'popout_transcript', 'popout_script_path']);
        });
    }
};
