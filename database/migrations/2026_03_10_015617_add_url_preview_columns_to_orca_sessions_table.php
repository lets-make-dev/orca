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
            $this->addUserIdColumn($table);
            $table->string('user_email')->nullable()->after('user_id');
            $table->string('route_handler', 500)->nullable()->after('user_email');
            $table->string('route_handler_type', 50)->nullable()->after('route_handler');
            $table->string('route_name')->nullable()->after('route_handler_type');
        });
    }

    /**
     * Add a user_id column that matches the type of the users table primary key.
     */
    private function addUserIdColumn(Blueprint $table): void
    {
        if (Schema::hasTable('users')) {
            $column = collect(Schema::getColumns('users'))->firstWhere('name', 'id');
            $type = strtolower($column['type_name'] ?? 'bigint');

            if (in_array($type, ['uuid', 'char', 'varchar', 'text', 'string'])) {
                $table->uuid('user_id')->nullable()->after('source_url');

                return;
            }
        }

        $table->unsignedBigInteger('user_id')->nullable()->after('source_url');
    }

    public function down(): void
    {
        Schema::table('orca_sessions', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'user_email', 'route_handler', 'route_handler_type', 'route_name']);
        });
    }
};
