<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_configurations', function (Blueprint $table) {
            // Add connection_type column with default 'agent' for backward compatibility
            $table->string('connection_type', 20)->default('agent')->after('agent_id');
            
            // Add index for performance
            $table->index('connection_type');
        });

        // Make agent_id nullable (for direct-connected devices)
        Schema::table('device_configurations', function (Blueprint $table) {
            $table->string('agent_id')->nullable()->change();
        });

        // Add check constraints (PostgreSQL)
        DB::statement("
            ALTER TABLE device_configurations 
            ADD CONSTRAINT chk_connection_type 
            CHECK (connection_type IN ('agent', 'mqtt', 'http', 'webhook'))
        ");

        DB::statement("
            ALTER TABLE device_configurations 
            ADD CONSTRAINT chk_agent_id_required 
            CHECK (
                (connection_type = 'agent' AND agent_id IS NOT NULL) OR
                (connection_type != 'agent')
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop constraints
        DB::statement("ALTER TABLE device_configurations DROP CONSTRAINT IF EXISTS chk_agent_id_required");
        DB::statement("ALTER TABLE device_configurations DROP CONSTRAINT IF EXISTS chk_connection_type");
        
        // Drop column and index
        Schema::table('device_configurations', function (Blueprint $table) {
            $table->dropIndex(['connection_type']);
            $table->dropColumn('connection_type');
        });

        // Make agent_id non-nullable again
        Schema::table('device_configurations', function (Blueprint $table) {
            $table->string('agent_id')->nullable(false)->change();
        });
    }
};
