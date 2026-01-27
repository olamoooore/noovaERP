<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Artist canonical table for canonical name management
        Schema::create('artist_canonical', function (Blueprint $table) {
            $table->id();
            $table->string('canonical_name', 255)->unique();
            $table->enum('canonical_type', ['artist', 'group']);
            $table->unsignedBigInteger('master_record_id');
            $table->json('metadata')->nullable(); // Additional canonical data
            $table->timestamps();

            $table->index('canonical_name', 'idx_canonical_name');
            $table->index('master_record_id', 'idx_master_record');
            $table->index(['canonical_type', 'canonical_name'], 'idx_type_name');
        });

        // Artist aliases table for name variations and translations
        Schema::create('artist_aliases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('canonical_id');
            $table->string('alias_name', 255);
            $table->enum('alias_type', ['stage_name', 'romanized', 'translated', 'former_name', 'nickname']);
            $table->string('language_code', 5)->nullable(); // ISO language code
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('canonical_id')->references('id')->on('artist_canonical')->onDelete('cascade');
            $table->unique(['alias_name', 'alias_type'], 'unique_alias');
            $table->index('canonical_id', 'idx_canonical_id');
            $table->index(['alias_name', 'is_active'], 'idx_alias_active');
            $table->index('alias_type', 'idx_alias_type');
        });

        // Artist merge log table for merge audit trails
        Schema::create('artist_merge_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_artist_id');
            $table->unsignedBigInteger('target_artist_id');
            $table->text('merge_reason');
            $table->json('merged_data'); // Snapshot of merged data
            $table->json('conflict_resolution')->nullable(); // How conflicts were resolved
            $table->unsignedBigInteger('merged_by_user_id');
            $table->timestamp('merged_at')->useCurrent();
            $table->boolean('is_reversible')->default(true);
            $table->timestamp('reversed_at')->nullable();

            $table->index('merged_at', 'idx_merged_at');
            $table->index('source_artist_id', 'idx_source_artist');
            $table->index('target_artist_id', 'idx_target_artist');
            $table->index('merged_by_user_id', 'idx_merged_by_user');
        });

        // Enhanced artist_events table to support many-to-many with roles
        if (Schema::hasTable('artist_events')) {
            Schema::table('artist_events', function (Blueprint $table) {
                if (!Schema::hasColumn('artist_events', 'performance_role')) {
                    $table->enum('performance_role', ['headliner', 'supporting', 'guest', 'collaboration'])->default('headliner');
                    $table->index('performance_role', 'idx_performance_role');
                }
                if (!Schema::hasColumn('artist_events', 'performance_order')) {
                    $table->integer('performance_order')->nullable();
                    $table->index('performance_order', 'idx_performance_order');
                }
                if (!Schema::hasColumn('artist_events', 'collaboration_type')) {
                    $table->enum('collaboration_type', ['solo', 'duet', 'group', 'band'])->nullable();
                }
                if (!Schema::hasColumn('artist_events', 'performance_metadata')) {
                    $table->json('performance_metadata')->nullable();
                }
                if (!Schema::hasColumn('artist_events', 'is_confirmed')) {
                    $table->boolean('is_confirmed')->default(true);
                    $table->index('is_confirmed', 'idx_is_confirmed');
                }
                if (!Schema::hasColumn('artist_events', 'confirmed_at')) {
                    $table->timestamp('confirmed_at')->nullable();
                }
            });
        }

        // Artist relationship tracking for groups and collaborations
        Schema::create('artist_relationships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('primary_artist_id');
            $table->unsignedBigInteger('related_artist_id');
            $table->enum('relationship_type', ['member', 'former_member', 'collaborator', 'producer', 'songwriter']);
            $table->date('relationship_start')->nullable();
            $table->date('relationship_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('relationship_metadata')->nullable();
            $table->timestamps();

            $table->foreign('primary_artist_id')->references('id')->on('artists')->onDelete('cascade');
            $table->foreign('related_artist_id')->references('id')->on('artists')->onDelete('cascade');
            $table->unique(['primary_artist_id', 'related_artist_id', 'relationship_type'], 'unique_artist_relationship');
            $table->index(['primary_artist_id', 'relationship_type'], 'idx_primary_relationship');
            $table->index(['related_artist_id', 'relationship_type'], 'idx_related_relationship');
            $table->index('is_active', 'idx_relationship_active');
        });

        // Artist search index for efficient searching with aliases
        Schema::create('artist_search_index', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('artist_id');
            $table->unsignedBigInteger('canonical_id');
            $table->string('search_term', 255);
            $table->enum('term_type', ['canonical', 'alias', 'romanized', 'translated']);
            $table->decimal('search_weight', 3, 2)->default(1.0); // Search relevance weight
            $table->string('language_code', 5)->nullable();
            $table->timestamps();

            $table->foreign('artist_id')->references('id')->on('artists')->onDelete('cascade');
            $table->foreign('canonical_id')->references('id')->on('artist_canonical')->onDelete('cascade');
            $table->index(['search_term', 'search_weight'], 'idx_search_term_weight');
            $table->index('artist_id', 'idx_search_artist');
            $table->index('canonical_id', 'idx_search_canonical');
            $table->index('term_type', 'idx_term_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artist_search_index');
        Schema::dropIfExists('artist_relationships');

        // Remove added columns from artist_events
        Schema::table('artist_events', function (Blueprint $table) {
            $table->dropIndex('idx_performance_role');
            $table->dropIndex('idx_performance_order');
            $table->dropIndex('idx_is_confirmed');

            $table->dropColumn([
                'performance_role',
                'performance_order',
                'collaboration_type',
                'performance_metadata',
                'is_confirmed',
                'confirmed_at'
            ]);
        });

        Schema::dropIfExists('artist_merge_log');
        Schema::dropIfExists('artist_aliases');
        Schema::dropIfExists('artist_canonical');
    }
};